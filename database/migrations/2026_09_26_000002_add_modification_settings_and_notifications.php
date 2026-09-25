<?php

use App\Services\NotificationRuleResolver;
use App\Services\SettingsService;
use App\Support\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * F3 — Modificaciones de obra: responsables configurables (quién solicita y quién
 * aprueba) y acciones de notificación con destinatarios editables en Configuración.
 * ADMIN/SUPERADMIN siempre pueden actuar; no dependen de estos ajustes.
 */
return new class extends Migration
{
    private const SETTINGS = [
        'modificaciones_roles_solicitantes' => ['INFRAESTRUCTURA'],
        'modificaciones_roles_aprobadores'  => ['AUDITORIA'],
    ];

    private const ACTIONS = [
        'Solicitud de modificacion de obra' => ['critical' => true,  'app' => ['AUDITORIA', 'SUPERADMIN', 'ADMIN'],                        'mail' => ['AUDITORIA']],
        'Aprobacion de modificacion de obra' => ['critical' => true, 'app' => ['SOLICITANTE', 'RESIDENTE_ASIGNADO', 'SUPERADMIN', 'ADMIN'], 'mail' => ['SOLICITANTE']],
        'Rechazo de modificacion de obra'   => ['critical' => true,  'app' => ['SOLICITANTE', 'SUPERADMIN', 'ADMIN'],                      'mail' => ['SOLICITANTE']],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::SETTINGS as $key => $default) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                ['group' => 'flujo', 'value' => json_encode($default), 'type' => 'json', 'min_value' => null, 'max_value' => null, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        $rules = [];
        foreach (self::ACTIONS as $action => $config) {
            DB::table('notification_actions')->updateOrInsert(
                ['key' => $action],
                ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => $config['critical'], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            );
            foreach (['app', 'mail'] as $channel) {
                foreach ($config[$channel] as $role) {
                    $rules[] = ['action' => $action, 'role' => $role, 'channel' => $channel, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }
        DB::table('notification_rules')->upsert($rules, ['action', 'role', 'channel'], ['enabled', 'updated_at']);

        $this->addToWhitelist('acciones_con_notificacion_app', array_keys(self::ACTIONS));
        $this->addToWhitelist('acciones_con_correo', array_keys(self::ACTIONS));

        NotificationCatalog::forget();
        SettingsService::forget();
        NotificationRuleResolver::forget();
    }

    public function down(): void
    {
        $keys = array_keys(self::ACTIONS);

        DB::table('notification_rules')->whereIn('action', $keys)->delete();
        DB::table('notification_actions')->whereIn('key', $keys)->delete();
        DB::table('app_settings')->whereIn('key', array_keys(self::SETTINGS))->delete();

        foreach (['acciones_con_notificacion_app', 'acciones_con_correo'] as $settingKey) {
            $setting = DB::table('app_settings')->where('key', $settingKey)->first();
            if ($setting !== null) {
                $remaining = array_values(array_diff(json_decode($setting->value, true) ?? [], $keys));
                DB::table('app_settings')->where('key', $settingKey)->update(['value' => json_encode($remaining), 'updated_at' => now()]);
            }
        }

        NotificationCatalog::forget();
        SettingsService::forget();
        NotificationRuleResolver::forget();
    }

    private function addToWhitelist(string $settingKey, array $actions): void
    {
        $setting = DB::table('app_settings')->where('key', $settingKey)->first();
        if ($setting === null) {
            return;
        }

        $current = json_decode($setting->value, true) ?? [];
        $missing = array_values(array_diff($actions, $current));
        if ($missing) {
            DB::table('app_settings')->where('key', $settingKey)->update(['value' => json_encode([...$current, ...$missing]), 'updated_at' => now()]);
        }
    }
};
