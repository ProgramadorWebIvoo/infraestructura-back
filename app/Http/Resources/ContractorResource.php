<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContractorResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'code'               => $this->code,
            'name'               => $this->name,
            'specialty'          => $this->specialty,
            'rating'             => $this->rating,
            'contact'            => $this->contact,
            'registrationSource' => $this->registration_source,
            'status'             => $this->status,
            'createdAt'          => optional($this->created_at)->format('Y-m-d H:i'),
            'updatedAt'          => optional($this->updated_at)->format('Y-m-d H:i'),
        ];
    }
}
