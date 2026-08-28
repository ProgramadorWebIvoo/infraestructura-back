<?php

namespace App\Services;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectProposal;
use App\Models\SupplierMaterialProposal;
use App\Models\ProductPriceHistory;
use Illuminate\Support\Facades\DB;

class SupplierProposalImportService
{
    /**
     * Importa las propuestas de materiales recibidas del portal de proveedores
     * como propuestas de contratista del proyecto, emparejando por contacto/nombre.
     * También guarda snapshot de precios en product_price_history para trazabilidad.
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

            // Calculate values from supplier material proposal
            $materialCost = collect($supplierProposal->items)->sum('totalPrice');
            $laborCost = $supplierProposal->labor_cost ?? 0;
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
            DB::transaction(function () use ($project, $supplierProposal, $contractor, $materialCost, $laborCost, $totalCost, $deliveryWeeks, $description) {
                $project->proposals()->create([
                    'id' => ProjectProposal::nextId(),
                    'contractor_code' => $contractor->code,
                    'contractor_name_snapshot' => $contractor->name,
                    'material_cost' => $materialCost,
                    'material_items' => $supplierProposal->items,
                    'quote_currency' => $supplierProposal->quote_currency,
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
                'price_usd' => $line->unit_price_usd, // MVP: solo USD
                'original_currency' => $line->quote_currency ?? 'USD',
                'original_price' => $line->unit_price,
                'fx_rate_to_usd' => 1.0, // MVP: sin conversión
                'fx_rate_source' => 'usd_only',
                'quoted_at' => $proposal->submitted_at ?? now(),
            ]);
        }
    }
}
