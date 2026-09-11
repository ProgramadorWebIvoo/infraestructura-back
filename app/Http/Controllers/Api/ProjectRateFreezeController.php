<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ProjectRateFreezeResource;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\ProjectRateFreeze;
use App\Services\RateFreezeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Congelaciones de tasa de cambio de un proyecto — el listado (index) es
 * de solo lectura para cualquier rol con visibilidad del proyecto; el
 * override manual (store) queda exclusivo SUPERADMIN (ver routes/api.php).
 */
class ProjectRateFreezeController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        $freezes = $project->rateFreezes()
            ->with('frozenByUser:id,name')
            ->orderByDesc('frozen_at')
            ->get();

        return response()->json(['data' => ProjectRateFreezeResource::collection($freezes)]);
    }

    public function store(Request $request, Project $project, RateFreezeService $service): JsonResponse
    {
        $data = $request->validate([
            'trigger' => ['required', Rule::in(ProjectRateFreeze::TRIGGERS)],
            'reason' => ['required', 'string', 'min:10', 'max:500'],
            'amountBase' => ['nullable', 'numeric', 'min:0'],
        ]);

        $freeze = $service->freezeManually($project, $data['trigger'], $data['reason'], $data['amountBase'] ?? null);

        AuditLog::record(
            $project,
            'SUPERADMIN',
            'Congelación manual de tasa de cambio',
            "Trigger: {$data['trigger']} / Motivo: {$data['reason']}"
        );

        return response()->json(['data' => new ProjectRateFreezeResource($freeze->load('frozenByUser:id,name'))], 201);
    }
}
