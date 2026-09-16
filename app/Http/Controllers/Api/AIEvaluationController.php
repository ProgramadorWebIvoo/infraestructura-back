<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\EvaluateProposalsWithAIJob;
use App\Models\Contractor;
use App\Models\Project;
use App\Services\AI\EvaluationPayload;
use App\Services\AI\EvaluationProject;
use App\Services\AI\EvaluationProposal;
use App\Services\AiFeatureGate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AIEvaluationController extends Controller
{
    /**
     * POST /api/ai/evaluate-proposals
     *
     * Recibe un proyecto con sus propuestas y encola su evaluación con IA
     * (failover automático entre proveedores) en EvaluateProposalsWithAIJob.
     * Antes evaluaba de forma síncrona: con hasta 3 providers x 60s de
     * timeout, un worker PHP-FPM podía bloquearse hasta 180s por request
     * (auditoría de rendimiento 2026-09-16). Ahora responde 202 de inmediato
     * y el resultado llega por GET .../status/{project} (poll) o el evento
     * Pusher `ai-evaluation.finished` en el canal privado del proyecto.
     */
    public function evaluate(Request $request)
    {
        // El mismo endpoint sirve a Procura (evaluación oficial) y Analistas
        // (vista previa) — el departamento del gate se resuelve por el rol
        // de quien llama, no por un parámetro del cliente (evitaría que
        // Analistas se salte su propio toggle pasando "PROCURA").
        $department = Auth::user()?->role === 'ANALISTA' ? 'ANALISTA' : 'PROCURA';
        $action = $department === 'ANALISTA' ? 'ia.analistas.evaluacion_propuestas' : 'ia.procura.evaluacion_propuestas';

        abort_unless(
            AiFeatureGate::isEnabled($department, $action),
            403,
            'La evaluación IA está deshabilitada para este departamento. Contacte a un SUPERADMIN.'
        );

        $data = $request->validate([
            // Sin 'exists:projects,id': esa regla hace un SELECT COUNT extra
            // que Project::findOrFail() de abajo vuelve redundante — ya
            // produce su propio 404 si el proyecto no existe (auditoría de
            // rendimiento 2026-09-16, round 3).
            'projectId'                => ['required', 'string'],
            'projectTitle'             => ['required', 'string', 'max:500'],
            'projectDescription'       => ['required', 'string', 'max:2000'],
            'projectLocation'          => ['required', 'string', 'max:500'],
            'projectType'              => ['required', 'string', 'max:100'],
            'approvedInvestmentAmount' => ['required', 'numeric', 'min:0'],
            'proposals'                => ['required', 'array', 'min:1'],
            'proposals.*.id'                      => ['required', 'string', 'max:50'],
            'proposals.*.contractorCode'           => ['required', 'string', 'max:50'],
            'proposals.*.contractorName'           => ['required', 'string', 'max:500'],
            'proposals.*.materialCost'             => ['required', 'numeric', 'min:0'],
            'proposals.*.laborCost'                => ['required', 'numeric', 'min:0'],
            'proposals.*.totalCost'                => ['required', 'numeric', 'min:0'],
            'proposals.*.deliveryWeeks'            => ['required', 'integer', 'min:0'],
            // Sin tope contra el máximo configurado en CONFIG APP — el anticipo
            // negociado puede exceder la política interna por renegociación con
            // el proveedor; el máximo configurado solo alerta, no bloquea.
            'proposals.*.negotiatedAdvancePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'proposals.*.description'              => ['required', 'string', 'max:2000'],
            'proposals.*.observations'             => ['nullable', 'string', 'max:2000'],
            'proposals.*.materialItems'                     => ['nullable', 'array'],
            'proposals.*.materialItems.*.materialName'      => ['required_with:proposals.*.materialItems', 'string', 'max:255'],
            'proposals.*.materialItems.*.quantity'           => ['required_with:proposals.*.materialItems', 'numeric', 'min:0'],
            'proposals.*.materialItems.*.unit'               => ['required_with:proposals.*.materialItems', 'string', 'max:50'],
            'proposals.*.materialItems.*.unitPrice'          => ['required_with:proposals.*.materialItems', 'numeric', 'min:0'],
            'proposals.*.materialItems.*.totalPrice'         => ['required_with:proposals.*.materialItems', 'numeric', 'min:0'],
            'proposals.*.materialItems.*.notes'              => ['nullable', 'string', 'max:500'],
            'proposals.*.durationValue' => ['nullable', 'integer', 'min:0'],
            'proposals.*.durationUnit'  => ['nullable', 'string', Rule::in(['dias', 'semanas', 'meses'])],
            'proposals.*.origen'                    => ['nullable', 'string', 'max:50'],
            'proposals.*.precioAnterior'            => ['nullable', 'numeric'],
            'proposals.*.precioNuevo'               => ['nullable', 'numeric'],
            'proposals.*.diferencia'                => ['nullable', 'numeric'],
            'proposals.*.motivo'                    => ['nullable', 'string', 'max:1000'],
            'proposals.*.motivoAnticipoExcedido'    => ['nullable', 'string', 'max:1000'],
            'proposals.*.fechaOferta'               => ['nullable', 'string'],
            'provider' => ['nullable', 'string', Rule::in(['chatgpt', 'gemini', 'claude'])],
        ]);

        $project = Project::findOrFail($data['projectId']);

        // Enriquecer propuestas con rating del contratista desde BD y construir DTOs
        $contractorCodes = array_unique(array_column($data['proposals'], 'contractorCode'));
        $contractorsByCode = Contractor::whereIn('code', $contractorCodes)
            ->get()
            ->keyBy('code');

        $proposalDtos = [];
        foreach ($data['proposals'] as $prop) {
            $rating = (float) ($contractorsByCode->get($prop['contractorCode'])?->rating ?? 4.0);
            $proposalDtos[] = new EvaluationProposal(
                id:                      $prop['id'],
                contractorCode:          $prop['contractorCode'],
                contractorName:          $prop['contractorName'],
                contractorRating:        $rating,
                materialCost:            (float) $prop['materialCost'],
                laborCost:               (float) $prop['laborCost'],
                totalCost:               (float) $prop['totalCost'],
                deliveryWeeks:           (int) $prop['deliveryWeeks'],
                negotiatedAdvancePercent: (float) $prop['negotiatedAdvancePercent'],
                description:             $prop['description'],
                observations:            $prop['observations'] ?? null,
                materialItems:           $prop['materialItems'] ?? null,
                durationValue:           isset($prop['durationValue']) ? (int) $prop['durationValue'] : null,
                durationUnit:            $prop['durationUnit'] ?? null,
                origen:                  $prop['origen'] ?? null,
                precioAnterior:          isset($prop['precioAnterior']) ? (float) $prop['precioAnterior'] : null,
                precioNuevo:             isset($prop['precioNuevo']) ? (float) $prop['precioNuevo'] : null,
                diferencia:              isset($prop['diferencia']) ? (float) $prop['diferencia'] : null,
                motivo:                  $prop['motivo'] ?? null,
                motivoAnticipoExcedido:  $prop['motivoAnticipoExcedido'] ?? null,
                fechaOferta:             $prop['fechaOferta'] ?? null,
            );
        }

        // Lista de materiales auditados del expediente (Cierre de Obra) — se toma
        // de la BD, no del cliente, para que la IA compare contra la base real.
        $projectMaterials = $project->materials()->get(['name', 'quantity', 'unit', 'estimated_unit_price', 'condition'])
            ->map(fn ($m) => [
                'name'                => $m->name,
                'quantity'            => (float) $m->quantity,
                'unit'                => $m->unit,
                'estimatedUnitPrice'  => (float) $m->estimated_unit_price,
                'condition'           => $m->condition,
            ])
            ->all();

        // Construir payload tipado para el servicio AI
        $payload = new EvaluationPayload(
            project: new EvaluationProject(
                projectId:                $data['projectId'],
                projectTitle:             $data['projectTitle'],
                projectDescription:       $data['projectDescription'],
                projectLocation:          $data['projectLocation'],
                projectType:              $data['projectType'],
                approvedInvestmentAmount: (float) $data['approvedInvestmentAmount'],
                materials:                $projectMaterials,
                estimatedTotal:           (float) $project->estimated_total,
            ),
            proposals: $proposalDtos,
        );

        // "processing" antes de encolar: si el usuario abre el modal mientras
        // el Job corre, GET status ya refleja que hay una evaluación en curso
        // en vez de mostrar el resultado obsoleto anterior.
        $project->update([
            'bid_evaluation_ai_status' => 'processing',
            'bid_evaluation_ai_error' => null,
        ]);

        EvaluateProposalsWithAIJob::dispatch(
            $data['projectId'],
            $payload->toArray(),
            $data['provider'] ?? null,
            Auth::id(),
        );

        return response()->json([
            'success' => true,
            'status'  => 'processing',
            'projectId' => $data['projectId'],
        ], 202);
    }

    /**
     * GET /api/ai/evaluate-proposals/status/{project}
     *
     * Respaldo por polling del evento Pusher `ai-evaluation.finished` (por
     * si el cliente perdió la conexión WebSocket mientras el Job corría).
     * Mismo shape de `data` que devolvía el endpoint síncrono anterior.
     */
    public function status(Project $project)
    {
        return response()->json([
            'success' => true,
            'status'  => $project->bid_evaluation_ai_status,
            'error'   => $project->bid_evaluation_ai_error,
            'data'    => $project->bid_evaluation_ai_status === 'completed' ? [
                'winnerContractorCode' => $project->bid_evaluation_ai_winner_code,
                'winnerContractorName' => $project->bid_evaluation_ai_winner_name,
                'confidenceScore'      => $project->bid_evaluation_ai_confidence_score,
                'summary'              => $project->bid_evaluation_ai_summary,
                'strengths'            => $project->bid_evaluation_ai_strengths,
                'weaknesses'           => $project->bid_evaluation_ai_weaknesses,
                'riskFactors'          => $project->bid_evaluation_ai_risk_factors,
                'recommendation'       => $project->bid_evaluation_ai_recommendation,
                'providerUsed'         => $project->bid_evaluation_ai_provider,
            ] : null,
        ]);
    }
}
