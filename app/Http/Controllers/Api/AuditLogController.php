<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditLogController extends Controller
{
    /**
     * Filtros server-side opcionales (todos combinables con AND), mismo
     * patrón que ConfigAuditLogController::index: `q` (texto libre sobre
     * action/details/observations/project_title_snapshot/user_name_snapshot),
     * `role`, `project_id`, `action` (coincidencia exacta), `user` (LIKE
     * sobre user_name_snapshot), `date_from`/`date_to` (rango inclusive sobre
     * logged_at, formato Y-m-d).
     *
     * Shape de respuesta anidado bajo `data` ({items, currentPage, lastPage,
     * total, perPage}) — igual que ConfigAuditLogController::index, y por la
     * misma razón: `apiFetch` (frontend) desenvuelve automáticamente
     * `json.data`, así que la metadata de paginación no puede vivir como
     * hermana de `data` (Laravel serializa un paginator con `data`/`total`/
     * `current_page` como hermanos al mismo nivel — se perdería todo menos
     * los items). Antes de este cambio este endpoint devolvía el paginator
     * "crudo" (compatible por casualidad con `apiFetch<AuditLog[]>`, que solo
     * veía el array de items) — normalizado para que `useAuditLogs` pueda
     * paginar/filtrar de verdad, igual que `useConfigAuditLogs`.
     *
     * `per_page` default 200 (Fase 3 del plan de refuerzo de auditorías):
     * mantiene compatible a los consumidores que traen el trail completo sin
     * pedir una página específica (useProjectsData/useProjects, para las
     * cuentas/KPIs agregadas de toda la app) — antes el default era 50 y
     * truncaba silenciosamente el historial global. La UI de auditoría
     * dedicada (Presidencia) pide explícitamente `per_page` más chico vía
     * `useAuditLogs`.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) ($request->get('per_page', 200)), 500);
        $page = max((int) $request->get('page', 1), 1);

        $logs = $this->filtered($request)->latest('logged_at')->paginate($perPage, ['*'], 'page', $page);

        return response()->json(['data' => [
            'items' => $logs->getCollection()->map(fn (AuditLog $log) => [
                'id' => $log->id,
                'projectId' => $log->project_id,
                'projectTitle' => $log->project_title_snapshot,
                'role' => $log->role,
                'userName' => $log->user_name_snapshot,
                'action' => $log->action,
                'timestamp' => optional($log->logged_at)->format('Y-m-d H:i'),
                'details' => $log->details,
                'observations' => $log->observations,
            ]),
            'currentPage' => $logs->currentPage(),
            'lastPage' => $logs->lastPage(),
            'total' => $logs->total(),
            'perPage' => $logs->perPage(),
        ]]);
    }

    /**
     * Exportación CSV — mismos filtros que `index()` (Fase 4 del plan de
     * refuerzo de auditorías), sin paginar: un export es, por definición,
     * "todo lo que coincide con el filtro", no una página. `cursor()` en vez
     * de `get()`/`chunk()` para no cargar en memoria un historial grande de
     * una sola vez. Requisito típico de compliance en obra pública: entregar
     * la bitácora a un tercero o auditor externo.
     */
    public function export(Request $request): StreamedResponse
    {
        $rows = $this->filtered($request)->latest('logged_at')->cursor();

        return ResponseFacade::streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Proyecto', 'Rol', 'Usuario', 'Accion', 'Fecha', 'Detalles', 'Observaciones']);

            foreach ($rows as $log) {
                fputcsv($out, [
                    $log->id,
                    $log->project_title_snapshot,
                    $log->role,
                    $log->user_name_snapshot,
                    $log->action,
                    optional($log->logged_at)->format('Y-m-d H:i:s'),
                    $log->details,
                    $log->observations,
                ]);
            }

            fclose($out);
        }, 'auditoria-proyectos-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function filtered(Request $request): Builder
    {
        $query = AuditLog::query();

        if ($q = trim((string) $request->get('q', ''))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('action', 'like', "%{$q}%")
                    ->orWhere('details', 'like', "%{$q}%")
                    ->orWhere('observations', 'like', "%{$q}%")
                    ->orWhere('project_title_snapshot', 'like', "%{$q}%")
                    ->orWhere('user_name_snapshot', 'like', "%{$q}%");
            });
        }

        if ($role = $request->get('role')) {
            $query->where('role', $role);
        }

        if ($projectId = $request->get('project_id')) {
            $query->where('project_id', $projectId);
        }

        if ($action = $request->get('action')) {
            $query->where('action', $action);
        }

        if ($user = trim((string) $request->get('user', ''))) {
            $query->where('user_name_snapshot', 'like', "%{$user}%");
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->whereDate('logged_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->get('date_to')) {
            $query->whereDate('logged_at', '<=', $dateTo);
        }

        return $query;
    }
}
