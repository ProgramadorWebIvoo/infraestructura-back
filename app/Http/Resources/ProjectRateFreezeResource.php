<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProjectRateFreezeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'trigger' => $this->trigger,
            'baseCurrency' => $this->base_currency,
            // Lo congelado son los Bs.: moneda del monto (la de cotización), monto en
            // esa moneda, tasa de ESA moneda (Bs. por unidad) y Bs. resultantes.
            'frozenCurrency' => $this->frozen_currency ?? $this->base_currency,
            'frozenRate' => $this->frozen_rate,
            'frozenAmount' => $this->frozen_amount ?? $this->frozen_amount_base,
            'frozenAmountBs' => $this->frozen_amount_bs,
            // Equivalente del monto en moneda base.
            'frozenAmountBase' => $this->frozen_amount_base,
            'source' => $this->source,
            'reason' => $this->reason,
            'frozenAt' => optional($this->frozen_at)->toIso8601String(),
            'frozenByName' => $this->whenLoaded('frozenByUser', fn () => $this->frozenByUser?->name),
            'supersededById' => $this->superseded_by_id,
        ];
    }
}
