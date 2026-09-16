<?php

namespace App\Services\AI;

use App\Models\Contractor;
use App\Models\ContractorRatingSuggestion;
use App\Models\RatingIaRunLog;
use App\Services\AiFeatureGate;
use App\Services\ContractorHistoryService;
use Illuminate\Support\Facades\Log;

/**
 * Evalúa en lote la sugerencia de rating IA (ContractorRatingSuggestionStrategy)
 * para todos los proveedores ACTIVE, y persiste el resultado en
 * contractor_rating_suggestions — a diferencia de
 * ContractorController::ratingSuggestion (consulta puntual, no persiste),
 * este batch es lo que dispara el cronjob (rating-ia:run) y queda disponible
 * también para un disparo manual (POST /rating-ia/run). No autoritativo: no
 * toca `Contractor.rating`, solo deja la sugerencia lista para que el admin
 * la revise.
 */
class RatingIaBatchService
{
    public function __construct(
        private ContractorHistoryService $historyService,
        private AIEvaluationService $aiService,
    ) {
    }

    public function run(bool $debug = false): RatingIaRunLog
    {
        $startedAt = now();
        $log = RatingIaRunLog::create([
            'started_at' => $startedAt,
            'status' => 'success',
        ]);

        if (!AiFeatureGate::isEnabled('CATALOGOS', 'ia.proveedores.sugerencia_rating')) {
            $log->update([
                'finished_at' => now(),
                'status' => 'failed',
                'error_message' => 'La sugerencia de rating por IA está deshabilitada para Proveedores (Config IA).',
            ]);
            return $log->fresh();
        }

        $contractors = Contractor::where('status', 'ACTIVE')->get();
        $evaluated = 0;
        $suggested = 0;
        $errors = 0;
        $debugLines = [];

        foreach ($contractors as $contractor) {
            try {
                $history = $this->historyService->getSupplierHistory($contractor->code);

                // Sin cotizaciones registradas no hay señal para la IA — evita
                // gastar una llamada en un historial vacío (mismo criterio que
                // el prompt le pide considerar para "ser conservador").
                if ((int) ($history['stats']['totalQuoteCount'] ?? 0) === 0) {
                    if ($debug) {
                        $debugLines[] = "{$contractor->code}: omitido (sin cotizaciones)";
                    }
                    continue;
                }

                $result = $this->aiService->evaluateContractorRating([
                    'stats' => $history['stats'],
                    'topProducts' => $history['topProducts'],
                ]);

                $evaluated++;

                ContractorRatingSuggestion::updateOrCreate(
                    ['contractor_code' => $contractor->code],
                    [
                        'current_rating' => $contractor->rating,
                        'suggested_rating' => $result['suggestedRating'] ?? null,
                        'confidence_score' => $result['confidenceScore'] ?? 0,
                        'rationale' => $result['rationale'] ?? null,
                        'provider' => $result['providerUsed'] ?? null,
                        'source' => 'batch',
                    ]
                );
                $suggested++;

                if ($debug) {
                    $debugLines[] = "{$contractor->code}: OK (sugerido {$result['suggestedRating']})";
                }
            } catch (\Throwable $e) {
                $errors++;
                Log::warning("RatingIA batch: falló evaluación de {$contractor->code}: {$e->getMessage()}");
                if ($debug) {
                    $debugLines[] = "{$contractor->code}: ERROR ({$e->getMessage()})";
                }
            }
        }

        $log->update([
            'finished_at' => now(),
            'contractors_evaluated' => $evaluated,
            'suggestions_generated' => $suggested,
            'errors_count' => $errors,
            'status' => $errors === 0 ? 'success' : ($suggested > 0 ? 'partial' : 'failed'),
            'debug_details' => $debug ? implode("\n", $debugLines) : null,
        ]);

        return $log->fresh();
    }
}
