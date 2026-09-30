<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class NotificationActionResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'        => $this->id,
            'key'       => $this->key,
            'label'     => $this->label,
            'group'     => $this->group,
            'scope'     => $this->scope,
            'critical'  => $this->critical,
            'isActive'  => $this->is_active,
            'appEnabled' => $this->app_enabled,
            'mailEnabled' => $this->mail_enabled,
            'recipientType' => $this->recipient_type,
            'createdAt' => optional($this->created_at)->format('Y-m-d H:i'),
            'updatedAt' => optional($this->updated_at)->format('Y-m-d H:i'),
        ];
    }
}
