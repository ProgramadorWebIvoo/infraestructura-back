<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class LocalizationResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'             => $this->id,
            'title'          => $this->title,
            'address'        => $this->address,
            'city'           => $this->city,
            'region'         => $this->region,
            'type'           => $this->type,
            'notes'          => $this->notes,
            'isActive'       => $this->is_active,
            'residentUserId' => $this->resident_user_id,
            'residentName'   => $this->resident?->name,
            'projectsCount'  => $this->whenCounted('projects'),
        ];
    }
}
