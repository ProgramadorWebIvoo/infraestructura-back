<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DashboardSummaryService;

class DashboardSummaryController extends Controller
{
    public function __invoke(DashboardSummaryService $summary)
    {
        return response()->json($summary->getSummary());
    }
}
