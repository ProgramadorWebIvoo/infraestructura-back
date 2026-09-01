<?php

namespace App\Services;

use App\Models\Contractor;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\Project;
use App\Models\ProjectProposal;
use App\Models\SupplierMaterialProposal;
use App\Models\ProductPriceHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SupplierProposalImportService
{
    /**
     * Importa las propuestas de materiales recibidas del portal de proveedores
     * como propuestas de contratista del proyecto, emparejando por contacto/nombre.
     * También guarda snapshot de precios en product_price_history para trazabilidad.
     *
     * Conversión de moneda: material_cost/labor_cost/total_cost SIEMPRE quedan
     * en la moneda BASE vigente (Currency::is_base) — es lo que el resto del
     * sistema (semáforo de presupuesto, comparativas de Procura, adjudicación)
     * asume al leerlos, y así se preserva sin tocar ese código. Cuando el
     * proveedor cotizó en una moneda distinta, se guarda además el monto
     * ORIGINAL (material_cost_original/labor_cost_original/total_cost_original),
     * la tasa efectivamente usada (fx_rate_to_base) y contra qué moneda base
     * se calculó (base_currency_at_import) — trazabilidad total y reversible:
     * ninguna conversión destruye el dato con el que el proveedor cotizó
     * realmente, y el histórico sigue siendo interpretable aunque la moneda
     * base cambie más adelante.
     *
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function import(Project $project): array
    {
        $supplierProposals = SupplierMaterialProposal::where('project_id', $project->id)
            ->with('lines')
            ->get();

        if ($supplierProposals->isEmpty()) {
            return ['imported' => 0, 'skipped' => 0, 'errors' => []];
        }

        $existingCodes = $project->proposals()->pluck('contractor_code')->toArray();
        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($supplierProposals as $supplierProposal) {
            // Find matching contractor by email or name
            $contractor = Contractor::where('email', $supplierProposal->supplier_contact)
                ->orWhere('name', $supplierProposal->supplier_name)
                ->first();

            if (!$contractor) {
                $skipped++;
                $errors[] = "No se encontró contratista registrado para: {$supplierProposal->supplier_name} ({$supplierProposal->supplier_contact})";
                continue;
            }

            // Skip if already has a proposal from this contractor
            if (in_array($contractor->code, $existingCodes)) {
                $skipped++;
                continue;
            }

            // Montos tal como los cotizó el proveedor, en quote_currency —
            // nunca se pierden, se guardan como "_original" más abajo.
            $materialCostOriginal = collect($supplierProposal->items)->sum('totalPrice');
            $laborCostOriginal = $supplierProposal->labor_cost ?? 0;
            $totalCostOriginal = $materialCostOriginal + $laborCostOriginal;

            $baseCurrency = Currency::where('is_base', true)->value('code') ?? 'USD';
            $quoteCurrency = strtoupper($supplierProposal->quote_currency ?? $baseCurrency);
            $quotedAt = $supplierProposal->submitted_at ?? now();

            if ($quoteCurrency === $baseCurrency) {
                $fxRateToBase = 1.0;
                $materialCost = $materialCostOriginal;
                $laborCost = $laborCostOriginal;
            } else {
                // Usar ConversionService para obtener tasa (con caché + outdated detection)
                $conversionResult = app(ConversionService::class)->convert(
                    1.0,
                    $quoteCurrency,
                    $baseCurrency,
                    $quotedAt
                );
                $fxRateToBase = $conversionResult->rate;

                // Alertar si tasa está outdated (>24 horas)
                if ($conversionResult->isOutdated) {
                    Log::warning("Tasa de cambio outdated: {$quoteCurrency}→{$baseCurrency} tiene >24h. Cotización: {$quotedAt}");
                }

                // Materiales: sumar unit_price_usd (ya en moneda base, ver
                // ProposalLineNormalizer) × quantity de cada línea normalizada
                // en vez de reconvertir el JSON crudo — evita duplicar la
                // lógica de conversión en dos lugares y usa el mismo dato ya
                // persistido que ve el detalle de línea por línea. Fallback
                // defensivo si por algún motivo no hay líneas (no debería
                // pasar: se normalizan al recibir la cotización del portal).
                $materialCost = $supplierProposal->lines->isNotEmpty()
                    ? $supplierProposal->lines->sum(fn ($line) => $line->unit_price_usd * $line->quantity)
                    : round($materialCostOriginal * $fxRateToBase, 4);
                $laborCost = round($laborCostOriginal * $fxRateToBase, 4);
            }

            $totalCost = $materialCost + $laborCost;

            // Convert estimated duration to weeks. Sin dato del proveedor, se deja en 0
            // (default real de la columna) en vez de inventar un plazo.
            $deliveryWeeks = match ($supplierProposal->duration_unit) {
                'dias' => $supplierProposal->estimated_days !== null
                    ? max(1, (int) ceil($supplierProposal->estimated_days / 7))
                    : 0,
                'meses' => $supplierProposal->estimated_days !== null
                    ? $supplierProposal->estimated_days * 4
                    : 0,
                'semanas' => $supplierProposal->estimated_days ?? 0,
                default => 0, // sin duration_unit => sin dato
            };

            $description = $supplierProposal->general_notes
                ?? "Propuesta de materiales de {$supplierProposal->supplier_name}. Presupuesto total de materiales: \$" . number_format($totalCost, 2);

            // Transacción por propuesta (no una sola para todo el import):
            // si falla la línea N, las 1..N-1 ya importadas se quedan —
            // mismo criterio que el resto del método (skip + continue, no
            // todo-o-nada). Lo que sí debe ser atómico es "propuesta +
            // su histórico de precios", para que nunca quede una sin la
            // otra si algo revienta a mitad de savePriceHistory().
            $isConverted = $quoteCurrency !== $baseCurrency;

            DB::transaction(function () use (
                $project, $supplierProposal, $contractor, $materialCost, $laborCost, $totalCost,
                $materialCostOriginal, $laborCostOriginal, $totalCostOriginal, $fxRateToBase,
                $baseCurrency, $isConverted, $deliveryWeeks, $description,
            ) {
                $project->proposals()->create([
                    'id' => ProjectProposal::nextId(),
                    'contractor_code' => $contractor->code,
                    'contractor_name_snapshot' => $contractor->name,
                    'material_cost' => $materialCost,
                    'material_items' => $supplierProposal->items,
                    'quote_currency' => $supplierProposal->quote_currency,
                    'material_cost_original' => $isConverted ? $materialCostOriginal : null,
                    'labor_cost_original' => $isConverted ? $laborCostOriginal : null,
                    'total_cost_original' => $isConverted ? $totalCostOriginal : null,
                    'fx_rate_to_base' => $isConverted ? $fxRateToBase : null,
                    'base_currency_at_import' => $isConverted ? $baseCurrency : null,
                    'labor_cost' => $laborCost,
                    'total_cost' => $totalCost,
                    'delivery_weeks' => $deliveryWeeks,
                    'duration_value' => $supplierProposal->estimated_days,
                    'duration_unit' => $supplierProposal->duration_unit,
                    'negotiated_advance_percent' => $supplierProposal->advance_percent ?? 0,
                    'description' => $description,
                    'origen' => 'PORTAL-PROV',
                    'fecha_oferta' => now()->toDateString(),
                    'created_by' => auth()->id(),
                ]);

                // Guardar snapshot de precios en product_price_history para trazabilidad
                $this->savePriceHistory($supplierProposal, $contractor->code);
            });

            $existingCodes[] = $contractor->code;
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }

    /**
     * Guarda snapshot de cada línea de propuesta en product_price_history.
     * Esto habilita histórico de precios y cálculo de variación posterior.
     */
    private function savePriceHistory(SupplierMaterialProposal $proposal, string $supplierCode): void
    {
        foreach ($proposal->lines as $line) {
            // Solo guardar líneas con producto del catálogo (no personalizados)
            if (!$line->catalog_product_id) {
                continue;
            }

            ProductPriceHistory::create([
                'catalog_product_id' => $line->catalog_product_id,
                'supplier_code' => $supplierCode,
                'supplier_material_proposal_line_id' => $line->id,
                // unit_price_usd ya viene calculado correctamente contra la
                // moneda base por ProposalLineNormalizer (rateBetween, no la
                // tasa BCV cruda) — reusar ese valor real en vez de hardcodear
                // fx_rate_to_usd=1.0 como antes, que era incorrecto para
                // cualquier propuesta que no fuera USD.
                'price_usd' => $line->unit_price_usd,
                'original_currency' => $line->quote_currency ?? 'USD',
                'original_price' => $line->unit_price,
                'fx_rate_to_usd' => $line->fx_rate_to_usd,
                'fx_rate_source' => $line->quote_currency && $line->quote_currency !== 'USD' ? 'bcv_rate' : 'usd_only',
                'quoted_at' => $proposal->submitted_at ?? now(),
            ]);
        }
    }
}
