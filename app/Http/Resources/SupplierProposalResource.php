<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

class SupplierProposalResource extends JsonResource
{
    public function toArray($request): array
    {
        // Si la propuesta tiene líneas estructuradas (después de importar),
        // transformar a formato compatible con frontend (materialName, unitPrice, etc)
        // e incluir campos de estimación (estimatedPriceDisplay, variationLabel, etc).
        // Sino, devolver el JSON original.
        $lines = $this->lines ?? collect();
        $items = $lines->isNotEmpty()
            ? $lines->map(fn ($line) => [
                'materialName' => $line->catalogProduct?->name ?? $line->custom_product_name,
                'quantity' => $line->quantity,
                'unit' => $line->unit,
                'unitPrice' => (float) $line->unit_price_usd,
                'totalPrice' => (float) ($line->unit_price_usd * $line->quantity),
                'notes' => $line->line_notes,
                'conditionStatus' => $line->condition_status,
                // Nuevos campos de estimación
                'estimatedPriceDisplay' => $line->estimated_price_display,
                'variationLabel' => $line->variation_label,
                'variationBadgeColor' => $line->variation_badge_color,
                'variationDirection' => $line->variation_direction,
            ])
            : $this->items;

        return [
            'id'                     => $this->id,
            'projectId'              => $this->project_id,
            'projectTitleSnapshot'   => $this->project_title_snapshot,
            'supplierName'           => $this->supplier_name,
            'supplierCompany'        => $this->supplier_company,
            'supplierContact'        => $this->supplier_contact,
            'quoteCurrency'          => $this->quote_currency,
            'items'                  => $items,
            'generalNotes'           => $this->general_notes,
            'estimatedDays'          => $this->estimated_days,
            'durationUnit'           => $this->duration_unit,
            'advancePercent'         => $this->advance_percent,
            'laborCost'              => $this->labor_cost,
            'submittedAt'            => optional($this->submitted_at)->format('Y-m-d H:i'),
        ];
    }
}
