<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use App\Services\AI\AIEvaluationService;
use Illuminate\Support\Facades\Log;

/**
 * Evaluación IA del expediente completo — herramienta de Cierre de Obra
 * para apoyar su revisión previa a aprobar/rechazar un proyecto CREADO (o
 * RECHAZADO_CIERRE en un reenvío corregido). Nunca lanza excepción: el
 * auditor debe poder seguir revisando/aprobando/rechazando aunque la IA
 * no esté disponible.
 */
class DossierEvaluationService
{
    private const REJECTION_ACTIONS = [
        'Rechazo de petición de obra',
        'Rechazo de cuadro comparativo',
    ];

    public function __construct(private AIEvaluationService $aiService)
    {
    }

    /**
     * Best-effort: nunca lanza. El bool de retorno es solo para
     * logging/tests — el caller no debe ramificar lógica de negocio sobre él.
     */
    public function evaluate(Project $project): bool
    {
        try {
            $payload = $this->buildPayload($project);
            $result = $this->aiService->evaluateDossier($payload);

            $project->update([
                'dossier_ai_score'                => $result['score'],
                'dossier_ai_summary'              => $result['summary'],
                'dossier_ai_alerts'               => $result['alerts'],
                'dossier_ai_recommendation'       => $result['recommendation'],
                'dossier_ai_suggested_amount'     => $result['suggestedAmount'],
                'dossier_ai_completeness_factors' => $result['completenessFactors'],
                'dossier_ai_provider'             => $result['providerUsed'],
                'dossier_ai_evaluated_at'         => now(),
            ]);

            AuditLog::record(
                $project,
                'CIERRE_DE_OBRA',
                'Evaluacion inteligente de expediente',
                sprintf('Evaluación via %s | Score: %d/100', $result['providerUsed'], $result['score'])
            );

            return true;
        } catch (\Throwable $e) {
            Log::warning("Dossier AI evaluation failed for project {$project->id}: {$e->getMessage()}");
            return false;
        }
    }

    private function buildPayload(Project $project): array
    {
        $project->loadMissing(['materials', 'documents']);

        $documentCounts = $project->documents
            ->groupBy('document_type')
            ->map->count();

        $rejectionHistory = $project->auditLogs()
            ->whereIn('action', self::REJECTION_ACTIONS)
            ->orderByDesc('logged_at')
            ->limit(10)
            ->get(['action', 'logged_at', 'details'])
            ->map(fn ($log) => [
                'action'   => $log->action,
                'loggedAt' => optional($log->logged_at)->toIso8601String(),
                'details'  => $log->details,
            ])->values()->all();

        return [
            'project' => [
                'projectId'          => $project->id,
                'projectTitle'       => $project->title,
                'projectDescription' => $project->description,
                'projectLocation'    => $project->location,
                'projectType'        => $project->type,
                'estimatedTotal'     => (float) $project->estimated_total,
                'cierreObraNotes'    => $project->cierre_obra_notes,
                'calculationsAdded'  => (bool) $project->calculations_added,
                'blueprintsCount'    => (int) $project->blueprints_count,
            ],
            'materials' => $project->materials->take(30)->map(fn ($m) => [
                'name'               => $m->name,
                'quantity'           => (float) $m->quantity,
                'unit'               => $m->unit,
                'estimatedUnitPrice' => (float) $m->estimated_unit_price,
                'condition'          => $m->condition,
            ])->values()->all(),
            'documentCounts' => [
                'CALC'       => $documentCounts['CALC'] ?? 0,
                'PLANO'      => $documentCounts['PLANO'] ?? 0,
                'FOTO'       => $documentCounts['FOTO'] ?? 0,
                'CORRECCION' => $documentCounts['CORRECCION'] ?? 0,
            ],
            'rejectionHistory' => $rejectionHistory,
        ];
    }
}
