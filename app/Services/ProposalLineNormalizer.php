<?php

namespace App\Services;

use App\Models\Currency;
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
 * La moneda es única por PEDIDO (`SupplierMaterialProposal.quote_currency`),
 * no por línea — un proveedor cotiza todo el pedido en una sola moneda; no
 * existe (ni existió nunca en producción) un `quoteCurrency` por ítem, así
 * que todas las líneas heredan la de la cabecera.
 *
 * `unit_price_usd`/`fx_rate_to_usd` (nombres históricos de columna) se
 * calculan siempre contra la moneda BASE vigente del sistema (Currency::is_base),
 * no contra "USD" a secas — hoy ambas coinciden porque la base es USD, pero
 * si la base cambiara, este cálculo sigue siendo correcto sin tocar código.
 * Usa `ExchangeRate::rateBetween()` (pivote en Bs.), no `bcvRateFor()`
 * directo: multiplicar por la tasa BCV cruda de una moneda no-Bs da
 * bolívares, no dólares — ver el bug que esto corrige, documentado en
 * `ExchangeRate::rateBetween()`.
 */
class ProposalLineNormalizer
{
    /**
     * @return SupplierMaterialProposalLine[]
     */
    public function normalize(SupplierMaterialProposal $proposal): array
    {
        $quotedAt = $proposal->submitted_at ?? now();
        $currency = strtoupper($proposal->quote_currency ?? 'USD');
        $baseCurrency = Currency::where('is_base', true)->value('code') ?? 'USD';
        $fxRate = ExchangeRate::rateBetween($currency, $baseCurrency, $quotedAt);
        $lines = [];

        foreach ($proposal->items as $item) {
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
                'warranty_value' => $item['warrantyValue'] ?? null,
                'warranty_unit' => $item['warrantyUnit'] ?? null,
                'image_path' => $item['imagePath'] ?? null,
                'line_notes' => $item['notes'] ?? null,
            ]);
        }

        return $lines;
    }
}
