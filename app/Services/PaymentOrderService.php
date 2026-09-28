<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\PaymentOrder;
use App\Models\Project;
use App\Models\ProjectProposal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Orden de pago digital (F4 Bloque B, D1/D10). Genera y congela la orden de
 * anticipo o finiquito con los datos ya calculados por F1/F2 — no recalcula
 * montos, solo los toma y los fija. El contenido no cambia tras crearse:
 * cualquier corrección de monto o proveedor exige anular (D10) y regenerar.
 */
class PaymentOrderService
{
    /**
     * Crea la orden vigente para el tipo dado. Si ya existe una vigente
     * (no anulada) para la misma obra y tipo, la anula primero — cubre el
     * caso de re-generación tras `award-rejection` sin dejar dos vigentes.
     */
    public function generate(Project $project, string $type): PaymentOrder
    {
        return DB::transaction(function () use ($project, $type) {
            $existing = PaymentOrder::where('project_id', $project->id)
                ->where('payment_type', $type)
                ->where('current_key', PaymentOrder::currentKeyFor($project->id, $type))
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->void($existing, 'Regenerada: nuevos datos de adjudicación o finiquito.');
            }

            $proposal = $project->proposals()->whereKey($project->selected_proposal_id)->first();
            abort_unless($proposal, 422, 'La obra no tiene una propuesta adjudicada.');

            $contractor = Contractor::where('code', $project->selected_contractor_code)->first();
            abort_unless($contractor, 422, 'El proveedor adjudicado ya no existe.');

            $amount = $type === PaymentOrder::TYPE_ADVANCE
                ? $this->advanceAmount($proposal)
                : $this->finiquitoAmount($project);

            $snapshot = $this->buildSnapshot($project, $proposal, $contractor, $type, $amount);
            $contentHash = $this->canonicalHash($snapshot);

            $order = PaymentOrder::create([
                'number' => $this->nextNumber(),
                'project_id' => $project->id,
                'proposal_id' => $proposal->id,
                'contractor_code' => $contractor->code,
                'payment_type' => $type,
                'amount' => $amount,
                'currency' => $proposal->quote_currency ?? 'USD',
                'exchange_rate' => $proposal->fx_rate_to_base ?? null,
                'snapshot' => $snapshot,
                'status' => PaymentOrder::STATUS_EN_FIRMA,
                'content_hash' => $contentHash,
                'current_key' => PaymentOrder::currentKeyFor($project->id, $type),
                'elaborated_by' => $this->resolveElaboratedBy($project),
                'created_by' => auth()->id(),
            ]);

            AuditLog::record(
                $project,
                auth()->user()?->role ?? 'SISTEMA',
                $type === PaymentOrder::TYPE_ADVANCE ? 'Generacion de orden de pago de anticipo' : 'Generacion de orden de pago de finiquito',
                "Orden #{$order->number} / Proveedor: {$contractor->name} / Monto: {$amount} {$order->currency}"
            );

            return $order;
        });
    }

    /** Anula la orden vigente de una obra y tipo, si existe. No falla si no hay ninguna (llamable siempre al rechazar la adjudicación). */
    public function voidCurrent(Project $project, string $type, string $reason): void
    {
        $order = PaymentOrder::where('project_id', $project->id)
            ->where('current_key', PaymentOrder::currentKeyFor($project->id, $type))
            ->first();

        if ($order) {
            $this->void($order, $reason);
        }
    }

    public function void(PaymentOrder $order, string $reason): PaymentOrder
    {
        abort_if($order->status === PaymentOrder::STATUS_PAGADA, 422, 'Una orden ya pagada no se puede anular.');

        $order->update([
            'status' => PaymentOrder::STATUS_ANULADA,
            'void_reason' => $reason,
            'current_key' => null,
        ]);

        AuditLog::record(
            $order->project,
            auth()->user()?->role ?? 'SISTEMA',
            'Anulacion de orden de pago',
            "Orden #{$order->number} anulada. Motivo: {$reason}"
        );

        return $order;
    }

    /**
     * Valida que la orden esté lista para que Finanzas pague: vigente,
     * firmada (o sin pasos configurados, ver Bloque C) y el monto coincide
     * con el que se va a pagar. Se llama desde `ProjectController::pay`
     * antes de abrir su transacción (regla 2.5: fuera de la transacción de
     * escritura, ya que solo lee).
     */
    public function assertReadyToPay(Project $project, string $type, float $amount): PaymentOrder
    {
        $order = PaymentOrder::where('project_id', $project->id)
            ->where('current_key', PaymentOrder::currentKeyFor($project->id, $type))
            ->first();

        if (!$order) {
            throw ValidationException::withMessages(['amount' => 'No existe una orden de pago vigente para esta obra.']);
        }

        if (!in_array($order->status, [PaymentOrder::STATUS_EN_FIRMA, PaymentOrder::STATUS_FIRMADA], true)) {
            throw ValidationException::withMessages(['amount' => 'La orden de pago no está en un estado válido para pagarse.']);
        }

        if (bccomp((string) $order->amount, number_format($amount, 2, '.', ''), 2) !== 0) {
            throw ValidationException::withMessages(['amount' => 'El monto no coincide con el de la orden de pago vigente.']);
        }

        return $order;
    }

    private function advanceAmount(ProjectProposal $proposal): float
    {
        return round((float) $proposal->total_cost * ((float) $proposal->negotiated_advance_percent / 100), 2);
    }

    private function finiquitoAmount(Project $project): float
    {
        $amount = $project->closureReport?->finiquito_amount;
        abort_unless($amount !== null, 422, 'El informe de cierre no tiene un monto de finiquito calculado.');

        return (float) $amount;
    }

    /** Usuario ANALISTA que envió el cuadro comparativo (D15: consta como "elaborado por", no firma). */
    private function resolveElaboratedBy(Project $project): ?int
    {
        return AuditLog::where('project_id', $project->id)
            ->where('action', 'Carga de cuadro comparativo')
            ->latest('logged_at')
            ->value('user_id');
    }

    private function nextNumber(): int
    {
        return (int) (PaymentOrder::lockForUpdate()->max('number')) + 1;
    }

    private function buildSnapshot(Project $project, ProjectProposal $proposal, Contractor $contractor, string $type, float $amount): array
    {
        return [
            'project' => [
                'id' => $project->id,
                'title' => $project->title,
                'location' => $project->location,
            ],
            'contractor' => [
                'code' => $contractor->code,
                'name' => $contractor->name,
                'rif' => $contractor->rif,
            ],
            'proposal' => [
                'id' => $proposal->id,
                'total_cost' => number_format((float) $proposal->total_cost, 2, '.', ''),
                'negotiated_advance_percent' => number_format((float) $proposal->negotiated_advance_percent, 2, '.', ''),
                'currency' => $proposal->quote_currency ?? 'USD',
            ],
            'payment_type' => $type,
            'amount' => number_format($amount, 2, '.', ''),
        ];
    }

    /** Serialización canónica: claves ordenadas recursivamente y montos como string de 2 decimales (ya vienen así desde buildSnapshot). */
    private function canonicalHash(array $snapshot): string
    {
        return hash('sha256', json_encode($this->sortRecursive($snapshot), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function sortRecursive(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->sortRecursive($value);
            }
        }

        return $data;
    }
}
