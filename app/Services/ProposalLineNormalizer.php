<?php

namespace App\Services;

use App\Models\ExchangeRate;
use App\Models\SupplierMaterialProposal;
use App\Models\SupplierMaterialProposalLine;

/**
 * Convierte `SupplierMaterialProposal.items` (JSON libre enviado por el
 * portal público) en filas `SupplierMaterialProposalLine` estructuradas.
 * `items` en la cabecera NO se toca — sigue siendo el snapshot de
 * compatibilidad de lo que el proveedor envió — esta clase solo agrega
 * filas derivadas.
 *
 * El formulario público (Fase 3, pendiente) todavía no captura moneda de
 * cotización, condición, specs técnicas ni vínculo a catálogo por línea —
 * hasta que ese trabajo aterrice, cada ítem se normaliza como: moneda USD
 * (fx_rate_to_usd = 1.0), condición "new", y producto personalizado
 * (catalog_product_id = null, ver CatalogSyncService::resolveOrCreate)
 * usando el nombre declarado. Los campos opcionales del item (quoteCurrency,
 * conditionStatus, technicalSpecs, catalogProductId, etc.) se leen si están
 * presentes, así el código no requiere cambios cuando el formulario los
 * empiece a enviar.
 */
class ProposalLineNormalizer
{
    /**
     * @return SupplierMaterialProposalLine[]
     */
    public function normalize(SupplierMaterialProposal $proposal): array
    {
        $quotedAt = $proposal->submitted_at ?? now();
        $lines = [];

        foreach ($proposal->items as $item) {
            $currency = strtoupper($item['quoteCurrency'] ?? 'USD');
            $fxRate = ExchangeRate::rateFor($currency, $quotedAt);
            $unitPrice = (float) ($item['unitPrice'] ?? 0);

            $lines[] = SupplierMaterialProposalLine::create([
                'supplier_material_proposal_id' => $proposal->id,
                'catalog_product_id' => $item['catalogProductId'] ?? null,
                'custom_product_name' => empty($item['catalogProductId']) ? ($item['materialName'] ?? null) : null,
                'condition_status' => $item['conditionStatus'] ?? 'new',
                'quote_currency' => $currency,
                'fx_rate_to_usd' => $fxRate,
                'unit_price' => $unitPrice,
                'unit_price_usd' => round($unitPrice * $fxRate, 4),
                'quantity' => (float) ($item['quantity'] ?? 0),
                'unit' => $item['unit'] ?? '',
                'technical_specs' => $item['technicalSpecs'] ?? [],
                'warranty_description' => $item['warrantyDescription'] ?? null,
                'warranty_months' => $item['warrantyMonths'] ?? null,
                'image_path' => $item['imagePath'] ?? null,
                'line_notes' => $item['notes'] ?? null,
            ]);
        }

        return $lines;
    }
}
