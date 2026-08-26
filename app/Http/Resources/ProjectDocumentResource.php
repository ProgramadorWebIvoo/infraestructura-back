<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ProjectDocumentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'           => $this->id,
            'documentType' => $this->document_type,
            'originalName' => $this->original_name,
            'mimeType'     => $this->mime_type,
            'sizeBytes'    => $this->size_bytes,
            'uploadedBy'   => $this->uploaded_by,
            'uploadedAt'   => $this->created_at?->toIso8601String(),
            'documentGroupId' => $this->document_group_id,
            'versionNumber'   => $this->version_number,
            'deletedAt'       => $this->deleted_at?->toIso8601String(),
        ];
    }
}
