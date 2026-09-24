<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProjectHistoryIndexRequest;
use App\Http\Resources\ProjectHistoryRowResource;
use App\Models\Project;
use App\Services\ProjectHistoryService;

class ProjectHistoryController extends Controller
{
    public function __construct(private ProjectHistoryService $history) {}

    public function index(ProjectHistoryIndexRequest $request)
    {
        $filters = $request->validated();
        $filters['withAlerts'] = $request->boolean('withAlerts');

        return ProjectHistoryRowResource::collection(
            $this->history->getList($filters, (int) ($filters['perPage'] ?? 15))
        );
    }

    public function show(Project $project)
    {
        return response()->json(['data' => $this->history->getDetail($project)]);
    }
}
