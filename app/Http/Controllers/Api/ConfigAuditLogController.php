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
        $perPage = min((int) $request->get('per_page', 50), 200);

        $logs = ConfigAuditLog::latest('changed_at')
            ->paginate($perPage)
            ->through(fn (ConfigAuditLog $log) => [
                'id' => $log->id,
                'settingKey' => $log->setting_key,
                'oldValue' => $log->old_value,
                'newValue' => $log->new_value,
                'userName' => $log->user_name_snapshot,
                'changedAt' => optional($log->changed_at)->format('Y-m-d H:i'),
            ]);

        return response()->json($logs);
    }
}
