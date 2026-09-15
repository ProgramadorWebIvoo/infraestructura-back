<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigAuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Response as ResponseFacade;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ConfigAuditLogController extends Controller
{
    /**
     * Historial de acciones administrativas — exclusivo de SUPERADMIN (ver
     * middleware de la ruta). Deliberadamente separado de /audit-logs, que
     * cualquier autenticado puede consultar (incluida Presidencia). Cubre
     * tanto cambios de CONFIG APP (`entityType: 'setting'`) como otras
     * acciones administrativas (usuarios, proveedores, materiales, IA,
     * matriz de notificaciones) en el mismo listado — `entityType` permite
     * a la UI distinguir/filtrar sin necesitar un segundo endpoint.
     *
     * Filtros server-side (todos opcionales, combinables con AND):
     * `q` (texto libre sobre setting_key/old_value/new_value/user_name_snapshot/
     * email actual del usuario), `entity_type`, `action` (coincidencia exacta
     * — el frontend arma el catálogo de acciones a partir de las mismas
     * entradas cargadas), `user` (LIKE sobre user_name_snapshot o el email
     * actual), `date_from`/`date_to` (rango inclusive sobre changed_at,
     * formato Y-m-d). Antes el filtrado vivía enteramente en el frontend
     * sobre solo la página cargada — no permitía encontrar un registro fuera
     * de las últimas 20 entradas.
     */
    public function index(Request $request): JsonResponse
    {
        $perPage = min((int) $request->get('per_page', 20), 200);
        $page = max((int) $request->get('page', 1), 1);

        $logs = $this->filtered($request)->latest('changed_at')->paginate($perPage, ['*'], 'page', $page);

        // El frontend (apiFetch) desenvuelve automáticamente `json.data`, así
        // que la metadata de paginación no puede vivir como hermana de
        // `data` (se perdería) — va anidada dentro de `data` junto a los
        // items, con `items` como clave separada para no chocar con `data`
        // del paginador de Laravel.
        return response()->json(['data' => [
            'items' => $logs->getCollection()->map(fn (ConfigAuditLog $log) => [
                'id' => $log->id,
                'entityType' => $log->entity_type,
                'action' => $log->action,
                'settingKey' => $log->setting_key,
                'oldValue' => $log->old_value,
                'newValue' => $log->new_value,
                'userId' => $log->user_id,
                'userName' => $log->user_name_snapshot,
                'userEmail' => $log->user?->email,
                'changedAt' => optional($log->changed_at)->format('Y-m-d H:i'),
            ]),
            'currentPage' => $logs->currentPage(),
            'lastPage' => $logs->lastPage(),
            'total' => $logs->total(),
            'perPage' => $logs->perPage(),
        ]]);
    }

    /**
     * Exportación CSV — mismos filtros que `index()` (Fase 4 del plan de
     * refuerzo de auditorías), sin paginar. Hereda la restricción SUPERADMIN
     * de la ruta.
     */
    public function export(Request $request): StreamedResponse
    {
        // lazy() en vez de cursor(): cursor() no batchea el eager-load de
        // `user` (N+1 por fila); lazy() sí, procesando en chunks por debajo
        // sin cargar todo el resultado en memoria a la vez.
        $rows = $this->filtered($request)->latest('changed_at')->lazy();

        return ResponseFacade::streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['ID', 'Tipo de entidad', 'Accion', 'Setting', 'Valor anterior', 'Valor nuevo', 'Usuario', 'Email', 'Fecha']);

            foreach ($rows as $log) {
                fputcsv($out, [
                    $log->id,
                    $log->entity_type,
                    $log->action,
                    $log->setting_key,
                    $log->old_value,
                    $log->new_value,
                    $log->user_name_snapshot,
                    $log->user?->email,
                    optional($log->changed_at)->format('Y-m-d H:i:s'),
                ]);
            }

            fclose($out);
        }, 'auditoria-configuracion-' . now()->format('Y-m-d') . '.csv', ['Content-Type' => 'text/csv']);
    }

    private function filtered(Request $request): Builder
    {
        // Eager-load de `user` (solo id+email) para exponer el email actual
        // del actor junto al nombre snapshot histórico, sin N+1 queries por
        // fila del listado.
        $query = ConfigAuditLog::query()->with(['user:id,email']);

        if ($q = trim((string) $request->get('q', ''))) {
            $query->where(function ($sub) use ($q) {
                $sub->where('setting_key', 'like', "%{$q}%")
                    ->orWhere('old_value', 'like', "%{$q}%")
                    ->orWhere('new_value', 'like', "%{$q}%")
                    ->orWhere('user_name_snapshot', 'like', "%{$q}%")
                    ->orWhere('action', 'like', "%{$q}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$q}%"));
            });
        }

        if ($entityType = $request->get('entity_type')) {
            $query->where('entity_type', $entityType);
        }

        if ($action = $request->get('action')) {
            $query->where('action', $action);
        }

        if ($user = trim((string) $request->get('user', ''))) {
            // Coincide contra el nombre snapshot (histórico) o el email
            // actual del usuario — cubre el caso de que alguien busque por
            // nombre viejo tras un rename, o directamente por email.
            $query->where(function ($sub) use ($user) {
                $sub->where('user_name_snapshot', 'like', "%{$user}%")
                    ->orWhereHas('user', fn ($u) => $u->where('email', 'like', "%{$user}%"));
            });
        }

        if ($dateFrom = $request->get('date_from')) {
            $query->whereDate('changed_at', '>=', $dateFrom);
        }

        if ($dateTo = $request->get('date_to')) {
            $query->whereDate('changed_at', '<=', $dateTo);
        }

        return $query;
    }
}
