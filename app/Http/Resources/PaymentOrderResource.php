<?php

namespace App\Http\Resources;

use App\Services\PaymentSignatureService;
use Illuminate\Http\Resources\Json\JsonResource;

class PaymentOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        $pendingRequiredSignature = app(PaymentSignatureService::class)->pendingRequiredSignature($this->resource);

        return [
            'id' => $this->id,
            'number' => $this->number,
            'projectId' => $this->project_id,
            'proposalId' => $this->proposal_id,
            'contractorCode' => $this->contractor_code,
            'paymentType' => $this->payment_type,
            // Obligación en la moneda de cotización (`currency`); `amountBase`
            // es el mismo compromiso en la moneda base (USD).
            'amount' => $this->amount,
            'amountBase' => $this->amount_base,
            'currency' => $this->currency,
            'exchangeRate' => $this->exchange_rate,
            'status' => $this->status,
            'contentHash' => $this->content_hash,
            'voidReason' => $this->void_reason,
            'elaboratedByName' => $this->whenLoaded('elaboratedBy', fn () => $this->elaboratedBy?->name),
            'snapshot' => $this->snapshot,
            'createdAt' => optional($this->created_at)->toIso8601String(),
            // Cómo se pagó realmente (solo cuando la orden ya está pagada y se cargó la relación).
            'payment' => $this->whenLoaded('payment', fn () => $this->payment ? new PaymentSettlementResource($this->payment) : null),
            'signatures' => $this->whenLoaded('signatures', fn () => PaymentOrderSignatureResource::collection($this->signatures)),
            // Firma obligatoria que le falta a ESTA orden para poder avanzar
            // (aprobación/pago) — el frontend la usa para deshabilitar esos
            // botones de antemano en vez de esperar al 422 (F4 Bloque C).
            'pendingRequiredSignature' => $pendingRequiredSignature ? [
                'label' => $pendingRequiredSignature->label,
                'role' => $pendingRequiredSignature->role,
                'userName' => $pendingRequiredSignature->user_id !== null ? $pendingRequiredSignature->user?->name : null,
            ] : null,
        ];
    }
}
