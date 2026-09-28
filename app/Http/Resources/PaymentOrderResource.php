<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class PaymentOrderResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'projectId' => $this->project_id,
            'proposalId' => $this->proposal_id,
            'contractorCode' => $this->contractor_code,
            'paymentType' => $this->payment_type,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'exchangeRate' => $this->exchange_rate,
            'status' => $this->status,
            'contentHash' => $this->content_hash,
            'voidReason' => $this->void_reason,
            'elaboratedByName' => $this->whenLoaded('elaboratedBy', fn () => $this->elaboratedBy?->name),
            'snapshot' => $this->snapshot,
            'createdAt' => optional($this->created_at)->toIso8601String(),
            'signatures' => $this->whenLoaded('signatures', fn () => PaymentOrderSignatureResource::collection($this->signatures)),
        ];
    }
}
