<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\Project;
use App\Services\AI\AIEvaluationService;
use App\Services\AI\EvaluationPayload;
use App\Services\AI\EvaluationProject;
use App\Services\AI\EvaluationProposal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class AIEvaluationController extends Controller
{
    private AIEvaluationService $aiService;

    public function __construct(AIEvaluationService $aiService)
    {
        $this->aiService = $aiService;
    }

    /**
     * POST /api/ai/evaluate-proposals
     *
     * Recibe un proyecto con sus propuestas y las evalúa usando IA
     * con failover automático entre proveedores.
     */
    public function evaluate(Request $request)
    {
        $data = $request->validate([
            'projectId'                => ['required', 'string', 'exists:projects,id'],
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

        try {
            $result = $this->aiService->evaluateWithProvider($payload->toArray(), $data['provider'] ?? null);

            $this->cacheEvaluation($project, $result);

            // Log de auditoría
            $this->logEvaluation($project, $result);

            return response()->json([
                'success' => true,
                'data'    => $result,
            ]);

        } catch (\Throwable $e) {
            Log::error("AI Evaluation failed for project {$data['projectId']}: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
                'attemptLog' => $this->aiService->getAttemptLog(),
            ], 503);
        }
    }

    /**
     * Persiste el resultado en el expediente para que el botón "Evaluación IA"
     * no dispare una nueva llamada a IA cada vez que se abre el modal — solo
     * "Re-evaluar" lo hace. Se invalida (columnas puestas a null) en
     * addProposal/renegotiateProposal/removeProposal, cualquier cambio al
     * conjunto de propuestas vuelve obsoleto un análisis ya hecho.
     */
    private function cacheEvaluation(Project $project, array $result): void
    {
        $project->update([
            'bid_evaluation_ai_winner_code' => $result['winnerContractorCode'] ?? null,
            'bid_evaluation_ai_winner_name' => $result['winnerContractorName'] ?? null,
            'bid_evaluation_ai_confidence_score' => $result['confidenceScore'] ?? null,
            'bid_evaluation_ai_summary' => $result['summary'] ?? null,
            'bid_evaluation_ai_strengths' => $result['strengths'] ?? [],
            'bid_evaluation_ai_weaknesses' => $result['weaknesses'] ?? [],
            'bid_evaluation_ai_risk_factors' => $result['riskFactors'] ?? [],
            'bid_evaluation_ai_recommendation' => $result['recommendation'] ?? null,
            'bid_evaluation_ai_provider' => $result['providerUsed'] ?? null,
            'bid_evaluation_ai_evaluated_at' => now(),
        ]);
    }

    /**
     * Registra en la bitácora de auditoría el resultado de la evaluación.
     *
     * `$action` es la constante fija 'Evaluacion inteligente de propuestas'
     * (antes interpolaba el nombre del ganador — 'Evaluación Inteligente -
     * ' . $winnerContractorName — un dato variable usado como identificador
     * de tipo de evento, que por diseño nunca podía coincidir con ningún
     * catálogo/filtro de acciones ni aparecer seleccionable en CONFIG APP).
     * El nombre del ganador sigue disponible, ahora solo en `$details`.
     */
    private function logEvaluation(Project $project, array $result): void
    {
        try {
            AuditLog::record(
                $project,
                'PROCURA',
                'Evaluacion inteligente de propuestas',
                sprintf(
                    'Evaluación via %s | Score: %d%% | Ganador: %s (%s) | Fortalezas: %d | Debilidades: %d',
                    $result['providerUsed'] ?? 'N/A',
                    $result['confidenceScore'] ?? 0,
                    $result['winnerContractorName'] ?? 'N/A',
                    $result['winnerContractorCode'] ?? 'N/A',
                    count($result['strengths'] ?? []),
                    count($result['weaknesses'] ?? [])
                ),
            );
        } catch (\Throwable $e) {
            // No debe romper la respuesta si falla el log
            Log::warning("No se pudo registrar auditoría AI: {$e->getMessage()}");
        }
    }
}
