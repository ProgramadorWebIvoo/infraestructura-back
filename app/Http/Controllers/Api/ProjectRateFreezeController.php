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

        $details = "Trigger: {$data['trigger']} / Motivo: {$data['reason']}";
        if ($freeze->frozen_amount !== null) {
            $details .= " / Congelado: {$freeze->frozen_amount} {$freeze->frozen_currency}";
            $details .= $freeze->frozen_rate !== null
                ? " a {$freeze->frozen_rate} Bs. = {$freeze->frozen_amount_bs} Bs."
                : ' (sin tasa disponible para la moneda)';
        }
        // amountBase solo se usa como respaldo si la obra no tiene propuesta ni orden vigente.
        if (isset($data['amountBase']) && $freeze->frozen_amount_base !== null && abs((float) $data['amountBase'] - (float) $freeze->frozen_amount_base) > 0.005) {
            $details .= " / amountBase recibido ({$data['amountBase']}) ignorado: se usó el monto vigente de la obra ({$freeze->frozen_amount_base})";
        }

        AuditLog::record($project, 'SUPERADMIN', 'Congelación manual de tasa de cambio', $details);

        return response()->json(['data' => new ProjectRateFreezeResource($freeze->load('frozenByUser:id,name'))], 201);
    }
}
