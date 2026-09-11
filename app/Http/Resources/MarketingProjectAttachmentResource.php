<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MarketingProjectAttachmentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'originalName' => $this->original_name,
            'mimeType' => $this->mime_type,
            'sizeBytes' => $this->size_bytes,
            'uploadedBy' => $this->uploaded_by,
            'uploadedAt' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
