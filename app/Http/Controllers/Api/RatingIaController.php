<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ContractorRatingSuggestion;
use App\Models\RatingIaRunLog;
use App\Services\AI\RatingIaBatchService;
use App\Services\SettingsService;
use Illuminate\Http\JsonResponse;

/**
 * Panel/administración del cronjob de RatingIA — mismo rol que
 * ExchangeRateController para el cronjob de tasas: disparo manual, últimas
 * corridas y las sugerencias vigentes por proveedor.
 */
class RatingIaController extends Controller
{
    public function run(RatingIaBatchService $batchService): JsonResponse
    {
        $debug = (bool) SettingsService::get('rating_ia_debug', false);
        $log = $batchService->run($debug);

        return response()->json([
            'success' => $log->status !== 'failed',
            'data' => $log,
        ], $log->status === 'failed' ? 500 : 200);
    }

    public function runLogs(): JsonResponse
    {
        $logs = RatingIaRunLog::orderByDesc('started_at')->limit(50)->get();
        return response()->json(['data' => $logs]);
    }

    public function suggestions(): JsonResponse
    {
        $suggestions = ContractorRatingSuggestion::with('contractor')
            ->orderByDesc('updated_at')
            ->get();

        return response()->json(['data' => $suggestions]);
    }
}
