<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigAuditLog;
use App\Models\NotificationRule;
use App\Services\NotificationRuleResolver;
use App\Support\NotificationCatalog;
use App\Support\Roles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Matriz configurable rol × acción × canal — exclusivo SUPERADMIN (más
 * estricto que /settings, por pedido explícito del usuario). Cada acción
 * del catálogo se edita como una unidad (PUT /notification-rules), no celda
 * por celda — coincide con la UI (una fila por acción). `action` va en el
 * body, no como path param: varias acciones del catálogo contienen espacios
 * y hasta una barra literal (ej. "Carga de hojas de calculo/cubicaciones"),
 * lo que haría frágil cualquier URL-encoding.
 */
class NotificationRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => [
            'actions' => NotificationCatalog::toDetailedOptions(),
            'roles' => Roles::VALID,
            'rules' => NotificationRuleResolver::matrix(),
            'unconfigured' => NotificationRuleResolver::unconfiguredActions(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string'],
            'app' => ['array'],
            'app.*' => [Rule::in(Roles::VALID)],
            'mail' => ['array'],
            'mail.*' => [Rule::in(Roles::VALID)],
        ]);

        $action = $data['action'];
        abort_unless(NotificationCatalog::exists($action), 404, 'Acción no reconocida.');

        $appRoles = array_values(array_unique($data['app'] ?? []));
        $mailRoles = array_values(array_unique($data['mail'] ?? []));

        if (NotificationCatalog::isCritical($action) && empty($appRoles)) {
            abort(422, 'Esta acción es crítica: debe tener al menos un rol configurado en el canal app (mínimo SUPERADMIN).');
        }

        $before = NotificationRuleResolver::matrix()[$action] ?? ['app' => [], 'mail' => []];

        DB::transaction(function () use ($action, $appRoles, $mailRoles) {
            NotificationRule::where('action', $action)->delete();

            $now = now();
            $rows = [];
            foreach ($appRoles as $role) {
                $rows[] = ['action' => $action, 'role' => $role, 'channel' => 'app', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now];
            }
            foreach ($mailRoles as $role) {
                $rows[] = ['action' => $action, 'role' => $role, 'channel' => 'mail', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now];
            }

            if (!empty($rows)) {
                DB::table('notification_rules')->insert($rows);
            }
        });

        NotificationRuleResolver::forget();

        $after = ['app' => $appRoles, 'mail' => $mailRoles];
        ConfigAuditLog::recordAdminAction(
            'notification_rule',
            "notification_rules.{$action}",
            json_encode($before),
            json_encode($after),
            "Regla de notificación para \"{$action}\" actualizada.",
            notifyAction: 'Modificacion de reglas de notificacion',
        );

        return response()->json(['data' => [
            'action' => $action,
            'app' => $appRoles,
            'mail' => $mailRoles,
        ]]);
    }
}
