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

    public function logFailure(string $errorMessage, ?array $debugTrace = null): void
    {
        ExchangeRateSyncLog::create([
            'status' => 'FAILURE',
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

    public function getLastSuccessfulSync()
    {
        return ExchangeRateSyncLog::where('status', 'SUCCESS')
            ->orderByDesc('executed_at')
            ->first();
    }
}
