<?php

namespace App\Services;

use App\Models\Currency;

/**
 * Convierte a la moneda base los montos de una propuesta cargada a mano o
 * renegociada (analista autenticado o proveedor por enlace público), y arma
 * las columnas de `project_proposals` con la trazabilidad de la conversión:
 * montos ORIGINALES, tasa usada (fx_rate_to_base) y moneda base vigente —
 * las mismas columnas que puebla SupplierProposalImportService para el
 * portal, así una oferta en EUR/USDT se ve igual venga de donde venga.
 *
 * El frontend envía los montos TAL COMO se digitaron, en `quoteCurrency`;
 * la conversión vive solo acá (el backend es la fuente de verdad, el cliente
 * solo muestra el equivalente en pantalla). material_cost/labor_cost/
 * total_cost siempre quedan en la moneda base: es lo que asume el resto del
 * sistema (semáforo de presupuesto, comparativas, adjudicación).
 */
class ProposalCurrencyConverter
{
    public function __construct(private ConversionService $conversionService) {}

    /**
     * @param array{quoteCurrency?: ?string, materialCost: float|int|string, laborCost: float|int|string, totalCost?: float|int|string} $data
     * @return array<string, mixed> columnas de project_proposals
     */
    public function columnsFor(array $data): array
    {
        $baseCurrency = Currency::where('is_base', true)->value('code') ?? 'USD';
        $requested = isset($data['quoteCurrency']) && $data['quoteCurrency'] !== '' ? strtoupper($data['quoteCurrency']) : null;
        $quoteCurrency = $requested ?? $baseCurrency;

        $materialCost = (float) $data['materialCost'];
        $laborCost = (float) $data['laborCost'];

        if ($quoteCurrency === $baseCurrency) {
            return [
                'quote_currency' => $requested,
                'material_cost' => $materialCost,
                'labor_cost' => $laborCost,
                'total_cost' => $materialCost + $laborCost,
                'material_cost_original' => null,
                'labor_cost_original' => null,
                'total_cost_original' => null,
                'fx_rate_to_base' => null,
                'base_currency_at_import' => null,
            ];
        }

        try {
            $rate = $this->conversionService->convert(1.0, $quoteCurrency, $baseCurrency)->rate;
        } catch (\Exception $e) {
            abort(422, "No hay tasa de cambio disponible para convertir {$quoteCurrency} a {$baseCurrency}.");
        }

        $materialInBase = round($materialCost * $rate, 4);
        $laborInBase = round($laborCost * $rate, 4);

        return [
            'quote_currency' => $quoteCurrency,
            'material_cost' => $materialInBase,
            'labor_cost' => $laborInBase,
            'total_cost' => $materialInBase + $laborInBase,
            'material_cost_original' => $materialCost,
            'labor_cost_original' => $laborCost,
            'total_cost_original' => $materialCost + $laborCost,
            'fx_rate_to_base' => $rate,
            'base_currency_at_import' => $baseCurrency,
        ];
    }
}
