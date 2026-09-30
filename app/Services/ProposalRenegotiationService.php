<?php

namespace App\Services;

use App\Models\Project;
use App\Models\ProjectProposal;
use Illuminate\Support\Facades\DB;

/**
 * Aplica una renegociación de propuesta: reemplaza sus términos por unos
 * nuevos SIN borrar ni sobrescribir el registro original — precio anterior,
 * precio nuevo, diferencia y motivo quedan permanentemente auditables. Usado
 * tanto por ProjectController::renegotiateProposal (analista autenticado)
 * como por RenegotiationInvitationController::submit (proveedor vía enlace
 * público) para no duplicar el flujo transaccional.
 */
class ProposalRenegotiationService
{
    public function __construct(private ProposalCurrencyConverter $currencyConverter) {}

    public function apply(Project $project, ProjectProposal $proposal, array $data): ProjectProposal
    {
        $precioAnterior = (float) $proposal->total_cost;
        // Los montos llegan en quoteCurrency; precio nuevo y diferencia se
        // guardan en la moneda base, comparables con el precio anterior.
        $currencyColumns = $this->currencyConverter->columnsFor($data);
        $precioNuevo = (float) $currencyColumns['total_cost'];

        $renegotiated = DB::transaction(function () use ($project, $proposal, $data, $precioAnterior, $precioNuevo, $currencyColumns) {
            $new = $project->proposals()->create([
                'id' => ProjectProposal::nextId(),
                'contractor_code' => $proposal->contractor_code,
                'contractor_name_snapshot' => $proposal->contractor_name_snapshot,
                ...$currencyColumns,
                'material_items' => \App\Support\ProposalMaterialItemsNormalizer::withCatalogIds($project, $data['materialItems'] ?? null),
                'delivery_weeks' => $data['deliveryWeeks'],
                'duration_value' => $data['durationValue'] ?? null,
                'duration_unit' => $data['durationUnit'] ?? null,
                'negotiated_advance_percent' => $data['negotiatedAdvancePercent'],
                'description' => $data['description'],
                'origen' => 'RENEGOCIACION',
                'fecha_oferta' => $data['fechaOferta'],
                'created_by' => $data['createdBy'] ?? null,
                'precio_anterior' => $precioAnterior,
                'precio_nuevo' => $precioNuevo,
                'diferencia' => $precioNuevo - $precioAnterior,
                'motivo' => $data['motivo'],
                'motivo_anticipo_excedido' => $data['motivoAnticipoExcedido'] ?? null,
            ]);

            $proposal->update(['replaced_by_id' => $new->id]);

            return $new;
        });

        \App\Support\CacheVersion::bump('contractor_history:' . $proposal->contractor_code);

        return $renegotiated;
    }
}
