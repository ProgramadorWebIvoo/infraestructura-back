<?php

namespace App\Services;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectProposal;
use App\Models\SupplierMaterialProposal;

class SupplierProposalImportService
{
    /**
     * Importa las propuestas de materiales recibidas del portal de proveedores
     * como propuestas de contratista del proyecto, emparejando por contacto/nombre.
     *
     * @return array{imported: int, skipped: int, errors: string[]}
     */
    public function import(Project $project): array
    {
        $supplierProposals = SupplierMaterialProposal::where('project_id', $project->id)->get();

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
            $laborCost = 0;
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

            $project->proposals()->create([
                'id' => ProjectProposal::nextId(),
                'contractor_code' => $contractor->code,
                'contractor_name_snapshot' => $contractor->name,
                'material_cost' => $materialCost,
                'material_items' => $supplierProposal->items,
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

            $existingCodes[] = $contractor->code;
            $imported++;
        }

        return ['imported' => $imported, 'skipped' => $skipped, 'errors' => $errors];
    }
}
