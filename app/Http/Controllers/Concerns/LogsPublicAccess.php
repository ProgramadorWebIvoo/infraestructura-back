<?php

namespace App\Http\Controllers\Concerns;

use App\Models\AuditLog;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Log de acceso a endpoints públicos (sin autenticación).
 *
 * Además del log de texto (Log::info), persiste el acceso en AuditLog
 * cuando hay un proyecto asociado, para tener un único historial de
 * auditoría consistente y consultable (antes solo quedaba en el log de
 * archivo). Aditivo: no reemplaza el Log::info existente.
 */
trait LogsPublicAccess
{
    private function logPublicAccess(Request $request, string $action, ?string $detail = null, ?Project $project = null): void
    {
        Log::info('PUBLIC_ACCESS', [
            'action'    => $action,
            'ip'        => $request->ip(),
            'user_agent'=> $request->userAgent(),
            'detail'    => $detail,
            'timestamp' => now()->toIso8601String(),
        ]);

        if ($project !== null) {
            // 'role' es un enum de BD sin valor "PUBLIC"; se usa 'SISTEMA'
            // para accesos públicos no autenticados (ver audit_logs migration).
            AuditLog::record($project, 'SISTEMA', $action, $detail);
        }
    }
}
