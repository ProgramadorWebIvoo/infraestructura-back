<?php

use App\Services\NotificationRuleResolver;
use App\Services\SettingsService;
use App\Support\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Cierre posterior a la ejecución (finiquito): acciones nuevas y sus
 * destinatarios por rol. Sin filas en notification_rules solo se notificaría
 * a SUPERADMIN/ADMIN; las listas blancas de app/correo se amplían aparte.
 */
return new class extends Migration
{
    private const ACTIONS = [
        'Envio de informe de cierre del contratista'      => ['critical' => true,  'app' => ['INFRAESTRUCTURA', 'SUPERADMIN', 'ADMIN'], 'mail' => ['INFRAESTRUCTURA']],
        'Visto bueno de residente al informe de cierre'   => ['critical' => true,  'app' => ['AUDITORIA', 'SUPERADMIN', 'ADMIN'],       'mail' => ['AUDITORIA']],
        'Rechazo de informe de cierre por residente'      => ['critical' => true,  'app' => ['INFRAESTRUCTURA', 'SUPERADMIN', 'ADMIN'], 'mail' => []],
        'Rechazo de informe de cierre por Auditoria'      => ['critical' => true,  'app' => ['INFRAESTRUCTURA', 'SUPERADMIN', 'ADMIN'], 'mail' => ['INFRAESTRUCTURA']],
        'Verificacion de finalizacion por Auditoria'      => ['critical' => true,  'app' => ['PROCURA', 'SUPERADMIN', 'ADMIN'],         'mail' => ['PROCURA']],
        'Solicitud de pago de finiquito'                  => ['critical' => true,  'app' => ['FINANZAS', 'SUPERADMIN', 'ADMIN'],        'mail' => ['FINANZAS']],
        'Devolucion de finiquito a Auditoria'             => ['critical' => true,  'app' => ['AUDITORIA', 'SUPERADMIN', 'ADMIN'],       'mail' => ['AUDITORIA']],
        'Asignacion de residente'                         => ['critical' => false, 'app' => ['INFRAESTRUCTURA', 'SUPERADMIN', 'ADMIN'], 'mail' => []],
    ];

    public function up(): void
    {
        $now = now();
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
        $this->addToWhitelist('acciones_con_correo', array_keys(array_filter(self::ACTIONS, fn ($c) => $c['mail'] !== [])));

        NotificationCatalog::forget();
        SettingsService::forget();
        NotificationRuleResolver::forget();
    }

    public function down(): void
    {
        $keys = array_keys(self::ACTIONS);

        DB::table('notification_rules')->whereIn('action', $keys)->delete();
        DB::table('notification_actions')->whereIn('key', $keys)->delete();

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
