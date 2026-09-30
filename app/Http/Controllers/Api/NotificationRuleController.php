<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConfigAuditLog;
use App\Models\NotificationAction;
use App\Models\NotificationRule;
use App\Services\NotificationRuleResolver;
use App\Support\NotificationCatalog;
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
            'roles' => NotificationRuleResolver::assignableRoles(),
            'rules' => NotificationRuleResolver::matrix(),
            'unconfigured' => NotificationRuleResolver::unconfiguredActions(),
        ]]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'action' => ['required', 'string'],
            'app' => ['array'],
            'app.*' => [Rule::in(NotificationRuleResolver::assignableRoles())],
            'mail' => ['array'],
            'mail.*' => [Rule::in(NotificationRuleResolver::assignableRoles())],
            'appEnabled' => ['sometimes', 'boolean'],
            'mailEnabled' => ['sometimes', 'boolean'],
        ]);

        $action = $data['action'];
        abort_unless(NotificationCatalog::exists($action), 404, 'Acción no reconocida.');

        $isExternal = NotificationCatalog::isExternal($action);
        $appRoles = $isExternal ? [] : array_values(array_unique($data['app'] ?? []));
        $mailRoles = $isExternal ? [] : array_values(array_unique($data['mail'] ?? []));

        $entry = NotificationAction::where('key', $action)->firstOrFail();
        $appEnabled = $isExternal ? false : ($data['appEnabled'] ?? $entry->app_enabled);
        $mailEnabled = $data['mailEnabled'] ?? $entry->mail_enabled;

        if (!$mailEnabled && in_array($action, NotificationCatalog::ALWAYS_ON_MAIL, true)) {
            abort(422, 'Este correo es un flujo de cuenta y no se puede desactivar.');
        }

        if (NotificationCatalog::isCritical($action) && (empty($appRoles) || !$appEnabled)) {
            abort(422, 'Esta acción es crítica: debe tener al menos un rol configurado en el canal app (mínimo SUPERADMIN) y el canal activo.');
        }

        $before = ($isExternal ? ['app' => [], 'mail' => []] : (NotificationRuleResolver::matrix()[$action] ?? ['app' => [], 'mail' => []]))
            + ['appEnabled' => $entry->app_enabled, 'mailEnabled' => $entry->mail_enabled];

        DB::transaction(function () use ($action, $appRoles, $mailRoles, $entry, $appEnabled, $mailEnabled) {
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

            $entry->update(['app_enabled' => $appEnabled, 'mail_enabled' => $mailEnabled]);
        });

        NotificationCatalog::forget();
        NotificationRuleResolver::forget();

        $after = ['app' => $appRoles, 'mail' => $mailRoles, 'appEnabled' => $appEnabled, 'mailEnabled' => $mailEnabled];
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
            'appEnabled' => $appEnabled,
            'mailEnabled' => $mailEnabled,
        ]]);
    }
}
