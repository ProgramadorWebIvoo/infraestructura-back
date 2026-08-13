<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigAuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConfigAuditLogController extends Controller
{
    /**
     * Historial de cambios en CONFIG APP — exclusivo de SUPERADMIN (ver
     * middleware de la ruta). Deliberadamente separado de /audit-logs, que
     * cualquier autenticado puede consultar (incluida Presidencia).
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 200);
        $page = max((int) $request->get('page', 1), 1);

        $logs = ConfigAuditLog::latest('changed_at')->paginate($perPage, ['*'], 'page', $page);

        // El frontend (apiFetch) desenvuelve automáticamente `json.data`, así
        // que la metadata de paginación no puede vivir como hermana de
        // `data` (se perdería) — va anidada dentro de `data` junto a los
        // items, con `items` como clave separada para no chocar con `data`
        // del paginador de Laravel.
        return response()->json(['data' => [
            'items' => $logs->getCollection()->map(fn (ConfigAuditLog $log) => [
                'id' => $log->id,
                'settingKey' => $log->setting_key,
                'oldValue' => $log->old_value,
                'newValue' => $log->new_value,
                'userName' => $log->user_name_snapshot,
                'changedAt' => optional($log->changed_at)->format('Y-m-d H:i'),
            ]),
            'currentPage' => $logs->currentPage(),
            'lastPage' => $logs->lastPage(),
            'total' => $logs->total(),
            'perPage' => $logs->perPage(),
        ]]);
    }
}
