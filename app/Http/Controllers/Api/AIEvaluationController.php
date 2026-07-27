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
            'proposals.*.negotiatedAdvancePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'proposals.*.description'              => ['required', 'string', 'max:2000'],
            'proposals.*.observations'             => ['nullable', 'string', 'max:2000'],
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
            );
        }

        // Construir payload tipado para el servicio AI
        $payload = new EvaluationPayload(
            project: new EvaluationProject(
                projectId:                $data['projectId'],
                projectTitle:             $data['projectTitle'],
                projectDescription:       $data['projectDescription'],
                projectLocation:          $data['projectLocation'],
                projectType:              $data['projectType'],
                approvedInvestmentAmount: (float) $data['approvedInvestmentAmount'],
            ),
            proposals: $proposalDtos,
        );

        try {
            $result = $this->aiService->evaluateWithProvider($payload->toArray(), $data['provider'] ?? null);

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
     * Registra en la bitácora de auditoría el resultado de la evaluación.
     */
    private function logEvaluation(Project $project, array $result): void
    {
        try {
            AuditLog::record(
                $project,
                'PROCURA',
                'Evaluación Inteligente - ' . $result['winnerContractorName'],
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
