<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigAuditLog;
use App\Services\AiFeatureGate;
use App\Support\AiFeatureCatalog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Activación/desactivación de IA por departamento (interruptor maestro) y
 * por acción específica (interruptor granular) — sección "Control por
 * Departamento" de Config IA. `index` es de lectura abierta a cualquier
 * autenticado (el frontend la necesita para ocultar botones de IA en
 * cualquier rol, no solo en el panel de administración); `update` es
 * exclusivo SUPERADMIN, igual que el resto de credenciales/config de IA.
 */
class AiFeatureToggleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'departments' => AiFeatureCatalog::departments(),
            'actions' => AiFeatureCatalog::toOptions(),
            'matrix' => AiFeatureGate::matrix(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'department' => ['required', 'string', Rule::in(AiFeatureCatalog::departments())],
            'action' => ['nullable', 'string'],
            'enabled' => ['required', 'boolean'],
        ]);

        $department = $data['department'];
        $action = $data['action'] ?? null;

        if ($action !== null) {
            abort_unless(
                AiFeatureCatalog::exists($action) && AiFeatureCatalog::department($action) === $department,
                404,
                'Acción de IA no reconocida para este departamento.'
            );
            AiFeatureGate::setActionEnabled($department, $action, $data['enabled']);
            $details = sprintf(
                'Departamento: %s | Acción: %s | %s',
                $department,
                AiFeatureCatalog::label($action),
                $data['enabled'] ? 'Habilitada' : 'Deshabilitada',
            );
        } else {
            AiFeatureGate::setDepartmentEnabled($department, $data['enabled']);
            $details = sprintf(
                'Departamento: %s | Interruptor maestro: %s',
                $department,
                $data['enabled'] ? 'Habilitado' : 'Deshabilitado',
            );
        }

        ConfigAuditLog::recordAdminAction(
            'ai_feature_toggle',
            'Modificacion de disponibilidad de IA por departamento',
            null,
            null,
            $details,
        );

        return response()->json(['data' => [
            'department' => $department,
            'action' => $action,
            'enabled' => $data['enabled'],
            'matrix' => AiFeatureGate::matrix(),
        ]]);
    }
}
