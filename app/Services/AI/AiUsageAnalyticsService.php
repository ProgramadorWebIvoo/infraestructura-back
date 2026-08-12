<?php

namespace App\Services\AI;

use App\Models\AiUsageLog;

class AiUsageAnalyticsService
{
    public function getUsageSummary(int $days): array
    {
        $since = now()->subDays($days);

        $daily = AiUsageLog::where('created_at', '>=', $since)
            ->selectRaw("DATE(created_at) as date")
            ->selectRaw("SUM(prompt_tokens) as prompt_tokens")
            ->selectRaw("SUM(completion_tokens) as completion_tokens")
            ->selectRaw("SUM(total_tokens) as total_tokens")
            ->selectRaw("SUM(cost_estimate) as cost")
            ->selectRaw("COUNT(*) as requests")
            ->selectRaw("SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_requests")
            ->selectRaw("SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_requests")
            ->groupByRaw("DATE(created_at)")
            ->orderBy('date')
            ->get();

        $byProvider = AiUsageLog::where('created_at', '>=', $since)
            ->selectRaw("provider")
            ->selectRaw("SUM(prompt_tokens) as prompt_tokens")
            ->selectRaw("SUM(completion_tokens) as completion_tokens")
            ->selectRaw("SUM(total_tokens) as total_tokens")
            ->selectRaw("SUM(cost_estimate) as cost")
            ->selectRaw("COUNT(*) as requests")
            ->groupBy('provider')
            ->get();

        $byModel = AiUsageLog::where('created_at', '>=', $since)
            ->selectRaw("provider, model")
            ->selectRaw("SUM(prompt_tokens) as prompt_tokens")
            ->selectRaw("SUM(completion_tokens) as completion_tokens")
            ->selectRaw("SUM(total_tokens) as total_tokens")
            ->selectRaw("SUM(cost_estimate) as cost")
            ->selectRaw("COUNT(*) as requests")
            ->groupByRaw("provider, model")
            ->orderBy('provider')
            ->get();

        $totals = AiUsageLog::where('created_at', '>=', $since)
            ->selectRaw("COALESCE(SUM(prompt_tokens), 0) as prompt_tokens")
            ->selectRaw("COALESCE(SUM(completion_tokens), 0) as completion_tokens")
            ->selectRaw("COALESCE(SUM(total_tokens), 0) as total_tokens")
            ->selectRaw("COALESCE(SUM(cost_estimate), 0) as total_cost")
            ->selectRaw("COUNT(*) as total_requests")
            ->selectRaw("COALESCE(SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END), 0) as successful_requests")
            ->selectRaw("COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) as failed_requests")
            ->first();

        return [
            'daily'      => $daily ?? [],
            'byProvider' => $byProvider ?? [],
            'byModel'    => $byModel ?? [],
            'totals'     => $totals ?? [
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'total_tokens'      => 0,
                'total_cost'        => 0,
                'total_requests'    => 0,
                'successful_requests' => 0,
                'failed_requests'   => 0,
            ],
        ];
    }
}
