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
            'frozenRate' => $this->frozen_rate,
            'frozenAmountBase' => $this->frozen_amount_base,
            'source' => $this->source,
            'reason' => $this->reason,
            'frozenAt' => optional($this->frozen_at)->toIso8601String(),
            'frozenByName' => $this->whenLoaded('frozenByUser', fn () => $this->frozenByUser?->name),
            'supersededById' => $this->superseded_by_id,
        ];
    }
}
