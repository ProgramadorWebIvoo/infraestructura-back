<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContractorDocumentTypeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'label' => $this->label,
            'isRequired' => $this->is_required,
            'isActive' => $this->is_active,
            'sortOrder' => $this->sort_order,
        ];
    }
}
