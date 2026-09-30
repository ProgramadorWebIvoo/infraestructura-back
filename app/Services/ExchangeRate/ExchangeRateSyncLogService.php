<?php

namespace App\Services\ExchangeRate;

use App\Models\ExchangeRateSyncLog;

class ExchangeRateSyncLogService
{
    public function logSuccess(int $ratesSynced, string $source, ?array $debugTrace = null): void
    {
        ExchangeRateSyncLog::create([
            'status' => 'SUCCESS',
            'source' => $source,
            'rates_synced' => $ratesSynced,
            'executed_at' => now(),
            'debug_details' => $debugTrace,
        ]);
    }

    public function logFailure(string $errorMessage, ?array $debugTrace = null, ?string $source = null): void
    {
        ExchangeRateSyncLog::create([
            'status' => 'FAILURE',
            'source' => $source,
            'error_message' => $errorMessage,
            'executed_at' => now(),
            'debug_details' => $debugTrace,
        ]);
    }

    public function getRecentLogs(int $limit = 50)
    {
        return ExchangeRateSyncLog::orderByDesc('executed_at')
            ->limit($limit)
            ->get();
    }

    /**
     * Última sincronización BCV exitosa: excluye la de USDT, que corre cada
     * 30 min y haría parecer "recién sincronizado" a un BCV caído hace días.
     */
    public function getLastSuccessfulSync()
    {
        return ExchangeRateSyncLog::where('status', 'SUCCESS')
            ->where(fn ($query) => $query->whereNull('source')->orWhere('source', 'not like', UsdtApiFetcher::SOURCE . '%'))
            ->orderByDesc('executed_at')
            ->first();
    }
}
