<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentSignatureStepResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'paymentType' => $this->payment_type,
            'stepOrder' => $this->step_order,
            'role' => $this->role,
            'userId' => $this->user_id,
            'userName' => $this->whenLoaded('user', fn () => $this->user?->name),
            'label' => $this->label,
            'isActive' => $this->is_active,
            'isRequired' => $this->is_required,
        ];
    }
}
