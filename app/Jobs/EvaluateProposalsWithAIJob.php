<?php

namespace App\Jobs;

use App\Events\AIEvaluationFinished;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Services\AI\AIEvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Corre AIEvaluationService::evaluateWithProvider() en el worker de cola en
 * vez del request HTTP — antes, con failover de hasta 3 providers x 60s,
 * AIEvaluationController::evaluate() podía bloquear un worker PHP-FPM hasta
 * 180s (auditoría de rendimiento 2026-09-16). El controller ahora solo
 * valida, marca el proyecto como "processing" y despacha este Job; el
 * resultado llega al frontend vía AIEvaluationFinished (Pusher) con polling
 * de GET /api/ai/evaluate-proposals/status/{project} como respaldo.
 */
class EvaluateProposalsWithAIJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 3 providers x 25s (AIEvaluationService::DEFAULT_TIMEOUT) + margen. */
    public int $timeout = 120;

    /** Un solo intento: el propio service ya hace failover entre providers;
     * reintentar el Job completo duplicaría llamadas a IA ya facturadas. */
    public int $tries = 1;

    public function __construct(
        private readonly string $projectId,
        private readonly array $payload,
        private readonly ?string $forcedProvider,
        private readonly ?int $requestedByUserId,
    ) {
    }

    public function handle(AIEvaluationService $aiService): void
    {
        $project = Project::find($this->projectId);
        if (!$project) {
            // El proyecto pudo borrarse entre el dispatch y la ejecución.
            return;
        }

        // AuditLog::record() lee auth()->user() (HasAuditActorSnapshot) para
        // el actor — sin esto, en el worker de cola quedaría null.
        // Auth::setUser() (no onceUsingId(), que requiere StatefulGuard y no
        // existe en el guard "sanctum"/RequestGuard usado en esta app) fija
        // el usuario resuelto solo para este proceso, sin tocar sesión/cookies.
        if ($this->requestedByUserId) {
            $user = User::find($this->requestedByUserId);
            if ($user) {
                Auth::setUser($user);
            }
        }

        try {
            $result = $aiService->evaluateWithProvider($this->payload, $this->forcedProvider, null, $this->requestedByUserId);

            $this->cacheEvaluation($project, $result);
            $this->logEvaluation($project, $result);

            AIEvaluationFinished::dispatch($this->projectId, 'completed', $result);
        } catch (Throwable $e) {
            Log::error("AI Evaluation job failed for project {$this->projectId}: {$e->getMessage()}");

            $project->update([
                'bid_evaluation_ai_status' => 'failed',
                'bid_evaluation_ai_error'  => $e->getMessage(),
            ]);

            AIEvaluationFinished::dispatch($this->projectId, 'failed', null, $e->getMessage());
        }
    }

    /**
     * Falla dura del Job (timeout agotado, excepción no capturada por el
     * try/catch de handle() antes de que el proceso muera). Deja el
     * proyecto en un estado terminal en vez de "processing" para siempre.
     */
    public function failed(Throwable $e): void
    {
        $project = Project::find($this->projectId);
        if (!$project) {
            return;
        }

        $project->update([
            'bid_evaluation_ai_status' => 'failed',
            'bid_evaluation_ai_error'  => $e->getMessage(),
        ]);

        AIEvaluationFinished::dispatch($this->projectId, 'failed', null, $e->getMessage());
    }

    /**
     * Persiste el resultado en el expediente para que el botón "Evaluación
     * IA" no dispare una nueva llamada a IA cada vez que se abre el modal —
     * solo "Re-evaluar" lo hace. Se invalida (columnas puestas a null) en
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
            'bid_evaluation_ai_status' => 'completed',
            'bid_evaluation_ai_error' => null,
        ]);
    }

    /**
     * Registra en la bitácora de auditoría el resultado de la evaluación.
     * `$action` es una constante fija (no interpola el nombre del ganador)
     * para que coincida con el catálogo/filtro de acciones — ver detalle en
     * el historial de AIEvaluationController::logEvaluation() (versión
     * síncrona previa a esta auditoría).
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
        } catch (Throwable $e) {
            Log::warning("No se pudo registrar auditoría AI: {$e->getMessage()}");
        }
    }
}
