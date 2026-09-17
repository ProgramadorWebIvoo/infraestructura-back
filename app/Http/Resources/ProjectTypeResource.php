<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProjectTypeResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'        => $this->id,
            'key'       => $this->key,
            'label'     => $this->label,
            'isActive'  => $this->is_active,
            'sortOrder' => $this->sort_order,
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i'),
            'updatedAt' => optional($this->updated_at)->format('Y-m-d H:i'),
        ];
    }
}
