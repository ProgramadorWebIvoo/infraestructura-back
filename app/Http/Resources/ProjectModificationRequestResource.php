<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\ProjectModificationRequest */
class ProjectModificationRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'projectId' => $this->project_id,
            'status' => $this->status,
            'reason' => $this->reason,
            'rejectionReason' => $this->rejection_reason,
            'reviewNotes' => $this->review_notes,
            'requestedByName' => $this->requester?->name,
            'reviewedByName' => $this->reviewer?->name,
            'reviewedAt' => $this->reviewed_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'netAmountUsd' => $this->netAmountUsd(),
            'items' => $this->items->map(fn ($i) => [
                'id' => $i->id,
                'materialId' => $i->project_material_id,
                'name' => $i->material?->name,
                'unit' => $i->material?->unit,
                'type' => $i->type,
                'quantity' => $i->quantity,
                'unitPriceUsd' => $i->unit_price_usd,
                'note' => $i->note,
            ]),
        ];
    }
}
