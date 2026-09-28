<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentOrderSignatureResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'stepId' => $this->step_id,
            'stepLabel' => $this->whenLoaded('step', fn () => $this->step?->label),
            'userId' => $this->user_id,
            'userName' => $this->whenLoaded('user', fn () => $this->user?->name),
            'role' => $this->role,
            'signedAt' => optional($this->signed_at)->toIso8601String(),
            'revokedAt' => optional($this->revoked_at)->toIso8601String(),
        ];
    }
}
