<?php

namespace App\Services\ExchangeRate;

use App\Models\ExchangeRateSyncLog;

class ExchangeRateSyncLogService
{
    public function logSuccess(int $ratesSynced, string $source): void
    {
        ExchangeRateSyncLog::create([
            'status' => 'SUCCESS',
            'source' => $source,
            'rates_synced' => $ratesSynced,
            'executed_at' => now(),
        ]);
    }

    public function logFailure(string $errorMessage): void
    {
        ExchangeRateSyncLog::create([
            'status' => 'FAILURE',
            'error_message' => $errorMessage,
            'executed_at' => now(),
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
