<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Pago realizado tal como lo registró Finanzas (ver PaymentSettlementService):
 * moneda y monto realmente pagados, tasa aplicada vs sugerida, equivalente
 * cubierto y diferencia contra la obligación, más las tasas congeladas de la
 * adjudicación y del pago. Compartido por la orden pagada y el histórico de
 * obra para que ambos muestren exactamente lo mismo.
 *
 * `paymentMode` es null en los pagos anteriores a este registro.
 */
class PaymentSettlementResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->payment_type,
            'paidDate' => optional($this->paid_date)->format('Y-m-d'),
            'bank' => $this->bank,
            'reference' => $this->reference,
            'notes' => $this->notes,
            // Importe en moneda base (lo que suman los agregados).
            'amountBase' => $this->amount,
            'baseCurrency' => $this->currency,
            'paymentMode' => $this->payment_mode,
            'obligationAmount' => $this->obligation_amount,
            'obligationCurrency' => $this->obligation_currency,
            'paidCurrency' => $this->paid_currency,
            'paidAmount' => $this->paid_amount,
            'appliedRate' => $this->applied_rate,
            'appliedRateSource' => $this->applied_rate_source,
            'suggestedRate' => $this->suggested_rate,
            'coveredAmount' => $this->covered_amount,
            'differenceAmount' => $this->difference_amount,
            'differenceReason' => $this->difference_reason,
            'contractRateFreeze' => $this->whenLoaded('contractRateFreeze', fn () => $this->contractRateFreeze ? new ProjectRateFreezeResource($this->contractRateFreeze) : null),
            'paymentRateFreeze' => $this->whenLoaded('paymentRateFreeze', fn () => $this->paymentRateFreeze ? new ProjectRateFreezeResource($this->paymentRateFreeze) : null),
        ];
    }
}
