<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupplierMaterialProposalLineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'supplier_material_proposal_id' => $this->supplier_material_proposal_id,
            'catalog_product_id' => $this->catalog_product_id,
            'custom_product_name' => $this->custom_product_name,

            // Producto
            'product_name' => $this->catalogProduct?->name ?? $this->custom_product_name,
            'condition_status' => $this->condition_status,

            // Cantidad y unidad
            'quantity' => $this->quantity,
            'unit' => $this->unit,

            // Precios
            'quote_currency' => $this->quote_currency,
            'unit_price' => $this->unit_price,
            'unit_price_usd' => $this->unit_price_usd,
            'total_price' => $this->unit_price_usd * $this->quantity,

            // Estimación y Variación (NUEVO)
            'estimated_price_usd' => $this->estimated_price_usd,
            'estimated_price_source' => $this->estimated_price_source,
            'estimated_price_display' => $this->estimated_price_display,
            'variation_percent' => $this->variation_percent,
            'variation_direction' => $this->variation_direction,
            'variation_label' => $this->variation_label,
            'variation_badge_color' => $this->variation_badge_color,

            // Detalles técnicos
            'technical_specs' => $this->technical_specs,
            'warranty_description' => $this->warranty_description,
            'warranty_value' => $this->warranty_value,
            'warranty_unit' => $this->warranty_unit,

            // Documentos
            'image_path' => $this->image_path,
            'line_notes' => $this->line_notes,

            // Timestamps
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
