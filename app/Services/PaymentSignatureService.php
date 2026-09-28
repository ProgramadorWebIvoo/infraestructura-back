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
     * Firma "silenciosa" desde una acción de negocio existente (Bloque C:
     * selectContractor, award-approval, send-to-finance, finiquito-request,
     * pay): si le toca a este usuario firmar el próximo paso, lo hace; si no
     * (no es su turno, no hay pasos configurados, o ya está completa), no
     * hace nada — el paso queda para la bandeja de la orden.
     */
    public function trySign(PaymentOrder $order, User $user, ?Request $request = null): void
    {
        try {
            $this->sign($order, $user, $request);
        } catch (ValidationException|\Symfony\Component\HttpKernel\Exception\HttpException) {
            // No era el turno de este usuario, no hay pasos configurados, o
            // la orden ya está completamente firmada — no bloquea la acción
            // que disparó el intento.
        }
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

    /**
     * Válido para que Finanzas pague: sin pasos configurados (D2), o con
     * todos los pasos firmados salvo, a lo sumo, el último — que `pay()`
     * firma dentro de su propia transacción.
     */
    public function assertReadyForPayment(PaymentOrder $order): void
    {
        $steps = $this->stepsFor($order->payment_type);
        if ($steps->isEmpty()) {
            return;
        }

        $signedStepIds = $order->signatures()->whereNull('revoked_at')->pluck('step_id')->all();
        $unsigned = $steps->reject(fn (PaymentSignatureStep $step) => in_array($step->id, $signedStepIds, true));

        if ($unsigned->count() > 1 || ($unsigned->count() === 1 && $unsigned->first()->id !== $steps->last()->id)) {
            throw ValidationException::withMessages(['amount' => 'La orden de pago no tiene todas las firmas requeridas.']);
        }
    }

    private function computeSignatureHash(string $documentHash, int $userId, \DateTimeInterface $signedAt): string
    {
        return hash_hmac('sha256', "{$documentHash}|{$userId}|{$signedAt->format('c')}", (string) config('app.key'));
    }
}
