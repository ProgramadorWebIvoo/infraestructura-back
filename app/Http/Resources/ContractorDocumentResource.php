<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class ContractorDocumentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'contractorCode' => $this->contractor_code,
            'documentTypeId' => $this->document_type_id,
            'documentTypeKey' => $this->whenLoaded('type', fn () => $this->type->key),
            'documentTypeLabel' => $this->whenLoaded('type', fn () => $this->type->label),
            'originalName' => $this->original_name,
            'mimeType' => $this->mime_type,
            'sizeBytes' => $this->size_bytes,
            'sha256' => $this->sha256,
            'versionNumber' => $this->version_number,
            'documentGroupId' => $this->document_group_id,
            'source' => $this->source,
            'uploadedBy' => $this->uploaded_by,
            'uploadedAt' => $this->created_at?->toIso8601String(),
            'deletedAt' => $this->deleted_at?->toIso8601String(),
        ];
    }
}
