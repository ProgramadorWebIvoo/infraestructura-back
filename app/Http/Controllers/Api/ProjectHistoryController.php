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

    /**
     * Misma forma paginada que /audit-logs ({items, currentPage, ...}): el
     * cliente desenvuelve `data` automáticamente y la meta de un resource
     * collection se perdería.
     */
    public function index(ProjectHistoryIndexRequest $request)
    {
        $filters = $request->validated();
        $filters['withAlerts'] = $request->boolean('withAlerts');

        $page = $this->history->getList($filters, (int) ($filters['perPage'] ?? 15));

        return response()->json([
            'items' => ProjectHistoryRowResource::collection($page->getCollection())->resolve(),
            'currentPage' => $page->currentPage(),
            'lastPage' => $page->lastPage(),
            'total' => $page->total(),
            'perPage' => $page->perPage(),
        ]);
    }

    /** Todas las obras que cumplen los filtros (tope ProjectHistoryService::EXPORT_LIMIT), sin paginar, para exportar. */
    public function export(ProjectHistoryIndexRequest $request)
    {
        $filters = $request->validated();
        $filters['withAlerts'] = $request->boolean('withAlerts');

        return response()->json([
            'items' => ProjectHistoryRowResource::collection($this->history->getExportRows($filters))->resolve(),
        ]);
    }

    public function show(Project $project)
    {
        return response()->json(['data' => $this->history->getDetail($project)]);
    }
}
