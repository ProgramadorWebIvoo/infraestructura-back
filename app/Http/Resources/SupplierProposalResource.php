<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierProposalResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id'                     => $this->id,
            'projectId'              => $this->project_id,
            'projectTitleSnapshot'   => $this->project_title_snapshot,
            'supplierName'           => $this->supplier_name,
            'supplierCompany'        => $this->supplier_company,
            'supplierContact'        => $this->supplier_contact,
            'quoteCurrency'          => $this->quote_currency,
            'items'                  => $this->items,
            'generalNotes'           => $this->general_notes,
            'estimatedDays'          => $this->estimated_days,
            'durationUnit'           => $this->duration_unit,
            'advancePercent'         => $this->advance_percent,
            'laborCost'              => $this->labor_cost,
            'submittedAt'            => optional($this->submitted_at)->format('Y-m-d H:i'),
        ];
    }
}
