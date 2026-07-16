<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Project;
use App\Services\AI\AIEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

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
            'projectTitle'             => ['required', 'string'],
            'projectDescription'       => ['required', 'string'],
            'projectLocation'          => ['required', 'string'],
            'projectType'              => ['required', 'string'],
            'approvedInvestmentAmount' => ['required', 'numeric', 'min:0'],
            'proposals'                => ['required', 'array', 'min:1'],
            'proposals.*.id'                      => ['required', 'string'],
            'proposals.*.contractorCode'           => ['required', 'string'],
            'proposals.*.contractorName'           => ['required', 'string'],
            'proposals.*.materialCost'             => ['required', 'numeric', 'min:0'],
            'proposals.*.laborCost'                => ['required', 'numeric', 'min:0'],
            'proposals.*.totalCost'                => ['required', 'numeric', 'min:0'],
            'proposals.*.deliveryWeeks'            => ['required', 'integer', 'min:0'],
            'proposals.*.negotiatedAdvancePercent' => ['required', 'numeric', 'min:0', 'max:100'],
            'proposals.*.description'              => ['required', 'string'],
            'proposals.*.observations'             => ['nullable', 'string'],
        ]);

        $project = Project::findOrFail($data['projectId']);

        // Construir payload para el servicio AI
        $payload = [
            'project'   => [
                'projectId'                => $data['projectId'],
                'projectTitle'             => $data['projectTitle'],
                'projectDescription'       => $data['projectDescription'],
                'projectLocation'          => $data['projectLocation'],
                'projectType'              => $data['projectType'],
                'approvedInvestmentAmount' => $data['approvedInvestmentAmount'],
            ],
            'proposals' => $data['proposals'],
        ];

        try {
            $result = $this->aiService->evaluate($payload);

            // Log de auditoría
            $this->logEvaluation($project, $result);

            return response()->json([
                'success' => true,
                'data'    => $result,
            ]);

        } catch (\RuntimeException $e) {
            Log::error("AI Evaluation failed for project {$data['projectId']}: {$e->getMessage()}");

            return response()->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 503);
        }
    }

    /**
     * Registra en la bitácora de auditoría el resultado de la evaluación.
     */
    private function logEvaluation(Project $project, array $result): void
    {
        try {
            $user = auth()->user();

            AuditLog::create([
                'id'                => 'LOG-' . now()->format('YmdHisv'),
                'project_id'        => $project->id,
                'project_title_snapshot' => $project->title,
                'role'              => 'PROCURA',
                'user_id'           => $user?->id,
                'user_name_snapshot' => $user?->name,
                'action'            => 'Evaluación Inteligente - ' . $result['winnerContractorName'],
                'logged_at'         => now(),
                'details'           => sprintf(
                    'Evaluación via %s | Score: %d%% | Ganador: %s (%s) | Fortalezas: %d | Debilidades: %d',
                    $result['providerUsed'] ?? 'N/A',
                    $result['confidenceScore'] ?? 0,
                    $result['winnerContractorName'] ?? 'N/A',
                    $result['winnerContractorCode'] ?? 'N/A',
                    count($result['strengths'] ?? []),
                    count($result['weaknesses'] ?? [])
                ),
            ]);
        } catch (\Throwable $e) {
            // No debe romper la respuesta si falla el log
            Log::warning("No se pudo registrar auditoría AI: {$e->getMessage()}");
        }
    }
}
