<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\PaymentOrder;
use App\Models\PaymentOrderSignature;
use App\Models\PaymentSignatureStep;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Firma electrónica simple con trazabilidad (F4 Bloque C, D2/D3/D14): sin
 * certificado ni PKI — usuario, rol, fecha, IP, agente y hash del documento.
 * Cadena 100% configurable; sin pasos configurados para un tipo de pago, ese
 * tipo no exige firmas (D2) y esta clase no interviene.
 */
class PaymentSignatureService
{
    public function stepsFor(string $paymentType)
    {
        return PaymentSignatureStep::forType($paymentType)->with('user:id,name,role')->get();
    }

    /** Primer paso configurado de la orden sin firma vigente (no revocada). */
    public function nextPendingStep(PaymentOrder $order): ?PaymentSignatureStep
    {
        $signedStepIds = $order->signatures()->whereNull('revoked_at')->pluck('step_id')->all();

        return $this->stepsFor($order->payment_type)->first(fn (PaymentSignatureStep $step) => !in_array($step->id, $signedStepIds, true));
    }

    /**
     * Primer paso OBLIGATORIO sin firmar — ignora los no obligatorios que
     * estén antes en el orden (D: "is_required" solo condiciona si el paso
     * bloquea; no bloquea su propio turno ni el de los que le siguen). Es lo
     * que usan las transiciones de negocio para decidir si avanzan; distinto
     * de `nextPendingStep()`, que es el turno estricto para firmar de verdad
     * y sí respeta el orden completo (obligatorios y no obligatorios).
     */
    private function nextRequiredPendingStep(PaymentOrder $order): ?PaymentSignatureStep
    {
        $signedStepIds = $order->signatures()->whereNull('revoked_at')->pluck('step_id')->all();

        return $this->stepsFor($order->payment_type)
            ->first(fn (PaymentSignatureStep $step) => $step->is_required && !in_array($step->id, $signedStepIds, true));
    }

    /**
     * Firma el paso que corresponde al usuario, en su turno estricto (Req
     * 2.5): no se puede firmar fuera de orden ni saltarse un paso. Si la
     * orden fue alterada después de crearse (el hash no coincide con el
     * recalculado), rechaza — ver PaymentOrderService::canonicalHash.
     */
    public function sign(PaymentOrder $order, User $user, ?Request $request = null): PaymentOrderSignature
    {
        abort_if($order->status === PaymentOrder::STATUS_ANULADA, 422, 'No se puede firmar una orden anulada.');
        abort_if($order->status === PaymentOrder::STATUS_PAGADA, 422, 'La orden ya fue pagada; no admite más firmas.');

        $step = $this->nextPendingStep($order);
        if (!$step) {
            throw ValidationException::withMessages(['signature' => 'No hay ningún paso de firma pendiente para esta orden.']);
        }

        if (!$step->canBeSignedBy($user)) {
            throw ValidationException::withMessages(['signature' => "No le corresponde firmar este paso (\"{$step->label}\")."]);
        }

        return DB::transaction(function () use ($order, $user, $step, $request) {
            $signature = PaymentOrderSignature::create([
                'payment_order_id' => $order->id,
                'step_id' => $step->id,
                'user_id' => $user->id,
                'role' => $user->role,
                'signed_at' => now(),
                'ip' => $request?->ip(),
                'user_agent' => $request ? mb_strimwidth((string) $request->userAgent(), 0, 255, '') : null,
                'document_hash' => $order->content_hash,
                'signature_hash' => $this->computeSignatureHash($order->content_hash, $user->id, now()),
            ]);

            if ($this->isFullySigned($order)) {
                $order->update(['status' => PaymentOrder::STATUS_FIRMADA]);
            }

            AuditLog::record(
                $order->project,
                $user->role,
                'Firma de orden de pago',
                "Orden #{$order->number} / Paso: {$step->label} / Firmado por: {$user->name}"
            );

            return $signature;
        });
    }

    /**
     * Firma "silenciosa" desde una acción de negocio existente: si le toca a
     * este usuario firmar el próximo paso, lo hace; si no (no es su turno, no
     * hay pasos configurados, o ya está completa), no hace nada — el paso
     * queda para la bandeja de la orden. NUNCA bloquea a quien la llama; para
     * eso está `assertCanProceed()`/`signOrSkip()`.
     *
     * SUPERADMIN nunca firma por esta vía, aunque `canBeSignedBy()` le dé
     * permiso para firmar cualquier paso — ese permiso es para el acto
     * EXPLÍCITO de entrar y hacer clic en "Firmar" (endpoint `sign()`), no
     * para que quede firmado como efecto colateral de aprobar/pagar/etc. Así
     * "puede hacer todo" nunca significa "en silencio" (decisión 2026-09-29).
     */
    public function trySign(PaymentOrder $order, User $user, ?Request $request = null): void
    {
        if ($user->role === 'SUPERADMIN') {
            return;
        }

        try {
            $this->sign($order, $user, $request);
        } catch (ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            // No era el turno de este usuario, no hay pasos configurados, o
            // la orden ya está completamente firmada — no bloquea la acción
            // que disparó el intento.
        }
    }

    /**
     * Rechaza (422) si hay un paso OBLIGATORIO pendiente que este usuario no
     * puede firmar — el gate real de "la transición no ocurre si falta la
     * firma anterior" (Req 2.5). Sin pasos obligatorios pendientes (no hay
     * cadena, todos firmados, o el que falta es opcional) no bloquea (D2).
     * Se usa en los puntos del circuito que SÍ son el lugar natural donde
     * debe firmarse un paso (selectContractor, finiquito-request, pay) —
     * nunca en confirmaciones intermedias como send-to-finance, para no
     * bloquear esperando una firma que solo puede darse más adelante en el
     * circuito (evita un candado cruzado).
     *
     * SUPERADMIN nunca pasa gratis por su bypass de `canBeSignedBy()`: como
     * `trySign()` nunca firma por él, dejarlo pasar aquí sería la misma
     * firma silenciosa que se quiere evitar — debe firmar explícitamente
     * primero (ver `sign()`) para que el paso deje de estar pendiente.
     */
    public function assertCanProceed(PaymentOrder $order, User $user): void
    {
        $step = $this->nextRequiredPendingStep($order);
        if (!$step) {
            return;
        }

        $canProceed = $user->role === 'SUPERADMIN' ? false : $step->canBeSignedBy($user);

        if (!$canProceed) {
            $signer = $step->user_id !== null ? ($step->user?->name ?? 'un usuario específico') : $step->role;
            throw ValidationException::withMessages([
                'signature' => "Falta la firma de «{$step->label}» ({$signer}) antes de continuar.",
            ]);
        }
    }

    /** `assertCanProceed()` + intento de firma en el mismo turno — el punto de integración típico de una transición del circuito. */
    public function signOrSkip(PaymentOrder $order, User $user, ?Request $request = null): void
    {
        $this->assertCanProceed($order, $user);
        $this->trySign($order, $user, $request);
    }

    /**
     * Mismo cálculo que `assertCanProceed()` pero para lectura (UI): el paso
     * obligatorio pendiente que bloquearía la próxima transición de esta
     * orden, o null si no hay ninguno. Usado para deshabilitar de antemano
     * los botones de aprobación/pago en vez de esperar al 422.
     */
    public function pendingRequiredSignature(PaymentOrder $order): ?PaymentSignatureStep
    {
        return $this->nextRequiredPendingStep($order);
    }

    /**
     * Autoriza ver el detalle de una orden: los roles tradicionales del
     * circuito (compatibilidad con el comportamiento previo, restringido por
     * middleware de ruta) o cualquier usuario que tenga un paso a su
     * nombre/rol en la cadena configurada para el tipo de pago de esta
     * orden — así un rol nuevo (INFRAESTRUCTURA, AUDITORIA, etc.) agregado
     * como firmante también puede ver "su" orden sin necesitar acceso a
     * Finanzas/Procura/Presidencia.
     */
    public function canViewOrder(PaymentOrder $order, User $user): bool
    {
        if (in_array($user->role, ['ADMIN', 'SUPERADMIN', 'PROCURA', 'PRESIDENCIA', 'FINANZAS'], true)) {
            return true;
        }

        return $this->stepsFor($order->payment_type)->contains(fn (PaymentSignatureStep $step) => $step->canBeSignedBy($user));
    }

    /**
     * Si el usuario NO tiene ningún paso activo configurado a su nombre/rol,
     * la bandeja de "Firmas pendientes" no debe ni aparecer en el sidebar
     * (F4 Bloque C): sin esto, todo rol vería la pestaña vacía siempre.
     *
     * SUPERADMIN puede firmar cualquier paso (bypass), así que para esa
     * cuenta basta con que exista CUALQUIER paso activo, sin importar de qué
     * rol — es su bandeja de supervisión de todo el circuito.
     */
    public function hasConfiguredStepsFor(User $user): bool
    {
        if ($user->role === 'SUPERADMIN') {
            return PaymentSignatureStep::where('is_active', true)->exists();
        }

        return PaymentSignatureStep::where('is_active', true)
            ->where(fn ($q) => $q->where('user_id', $user->id)->orWhere('role', $user->role))
            ->exists();
    }

    /**
     * Órdenes de pago vigentes (no pagadas/anuladas) donde AHORA le toca
     * firmar a este usuario — mismo criterio que `canSign` en
     * PaymentOrderController::show, pero across todas las órdenes en vez de
     * una sola. Alimenta la bandeja "Firmas pendientes" del sidebar.
     *
     * @return \Illuminate\Support\Collection<int, PaymentOrder>
     */
    public function pendingSignaturesFor(User $user)
    {
        return PaymentOrder::whereNotNull('current_key')
            ->whereNotIn('status', [PaymentOrder::STATUS_PAGADA, PaymentOrder::STATUS_ANULADA])
            ->get()
            ->filter(function (PaymentOrder $order) use ($user) {
                $step = $this->nextPendingStep($order);
                return $step !== null && $step->canBeSignedBy($user);
            })
            ->values();
    }

    /**
     * Línea de firmas para el frontend: cada paso configurado con su estado
     * (firmado / próximo / pendiente) y quién firmó, en orden.
     *
     * @return array<int, array{step: PaymentSignatureStep, status: string, signature: ?PaymentOrderSignature}>
     */
    public function signatureLine(PaymentOrder $order): array
    {
        $signaturesByStep = $order->signatures()->with('user:id,name')->whereNull('revoked_at')->get()->keyBy('step_id');
        $next = $this->nextPendingStep($order);

        return $this->stepsFor($order->payment_type)->map(function (PaymentSignatureStep $step) use ($signaturesByStep, $next) {
            $signature = $signaturesByStep->get($step->id);
            $status = $signature ? 'FIRMADO' : ($next && $next->id === $step->id ? 'PROXIMO' : 'PENDIENTE');

            return ['step' => $step, 'status' => $status, 'signature' => $signature];
        })->all();
    }

    public function isFullySigned(PaymentOrder $order): bool
    {
        return $this->nextPendingStep($order) === null;
    }

    private function computeSignatureHash(string $documentHash, int $userId, \DateTimeInterface $signedAt): string
    {
        return hash_hmac('sha256', "{$documentHash}|{$userId}|{$signedAt->format('c')}", (string) config('app.key'));
    }
}
