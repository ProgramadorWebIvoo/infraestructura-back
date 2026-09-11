<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class MarketingProjectResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'type' => $this->type,
            'description' => $this->description,
            'location' => $this->location,
            'startDate' => optional($this->start_date)->format('Y-m-d'),
            'endDate' => optional($this->end_date)->format('Y-m-d'),
            'quantity' => $this->quantity,
            'estimatedCost' => $this->estimated_cost,
            'priority' => $this->priority,
            'status' => $this->status,
            'rejectionReason' => $this->rejection_reason,
            'requestedBy' => $this->requested_by,
            'requestedByName' => $this->whenLoaded('requestedBy', fn () => $this->requestedBy?->name),
            'reviewedBy' => $this->reviewed_by,
            'reviewedByName' => $this->whenLoaded('reviewedBy', fn () => $this->reviewedBy?->name),
            'reviewedAt' => optional($this->reviewed_at)->toIso8601String(),
            'attachments' => MarketingProjectAttachmentResource::collection($this->whenLoaded('attachments')),
            'createdAt' => optional($this->created_at)->toIso8601String(),
            'updatedAt' => optional($this->updated_at)->toIso8601String(),
        ];
    }
}
