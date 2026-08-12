<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MaterialResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                 => $this->id,
            'name'               => $this->name,
            'unit'               => $this->unit,
            'estimatedUnitPrice' => (float) $this->estimated_unit_price,
            'isActive'           => $this->is_active,
            'createdAt'          => optional($this->created_at)->format('Y-m-d H:i'),
            'updatedAt'          => optional($this->updated_at)->format('Y-m-d H:i'),
        ];
    }
}
