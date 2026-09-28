<?php

use App\Services\NotificationRuleResolver;
use App\Services\SettingsService;
use App\Support\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Informes independientes del contratista y del residente: avisos del flujo nuevo
 * (aparecen en Configuración > Notificaciones). El residente se entera al liberarse
 * el anticipo (ya puede cargar su informe) y Auditoría cuando llegan ambos informes.
 */
return new class extends Migration
{
    private const S = 'SOLICITANTE';
    private const R = 'RESIDENTE_ASIGNADO';

    /** acción => config; `new` = alta en el catálogo. */
    private const ACTIONS = [
        'Informe de verificación del residente' => ['new' => true, 'critical' => false, 'app' => [self::S, 'SUPERADMIN', 'ADMIN'], 'mail' => []],
        'Informes de cierre listos para Auditoria' => ['new' => true, 'critical' => true, 'app' => ['AUDITORIA', 'SUPERADMIN', 'ADMIN'], 'mail' => ['AUDITORIA']],
        'Envio de informe de cierre del contratista' => ['app' => [self::S, 'SUPERADMIN', 'ADMIN'], 'mail' => []],
    ];

    /** Retiradas: el residente ya no rechaza al contratista ni hay "visto bueno" previo a Auditoría. */
    private const RETIRED = ['Rechazo de informe de cierre por residente', 'Visto bueno de residente al informe de cierre'];

    public function up(): void
    {
        $now = now();

        foreach (self::ACTIONS as $action => $cfg) {
            if ($cfg['new'] ?? false) {
                DB::table('notification_actions')->updateOrInsert(
                    ['key' => $action],
                    ['label' => null, 'group' => 'proyectos', 'scope' => 'project', 'critical' => $cfg['critical'], 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                );
            }
            DB::table('notification_rules')->where('action', $action)->delete();
            $rows = [];
            foreach (['app', 'mail'] as $channel) {
                foreach ($cfg[$channel] as $role) {
                    $rows[] = ['action' => $action, 'role' => $role, 'channel' => $channel, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            if ($rows) {
                DB::table('notification_rules')->insert($rows);
            }
        }

        // Al liberar el anticipo se abre el informe: el residente ya puede cargar el suyo.
        DB::table('notification_rules')->upsert(
            [
                ['action' => 'Liberacion de anticipo', 'role' => self::R, 'channel' => 'app', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
                ['action' => 'Liberacion de anticipo', 'role' => self::R, 'channel' => 'mail', 'enabled' => true, 'created_at' => $now, 'updated_at' => $now],
            ],
            ['action', 'role', 'channel'],
            ['enabled', 'updated_at'],
        );

        DB::table('notification_actions')->whereIn('key', self::RETIRED)->update(['is_active' => false, 'updated_at' => $now]);

        $this->addToWhitelist('acciones_con_notificacion_app', ['Informe de verificación del residente', 'Informes de cierre listos para Auditoria']);
        $this->addToWhitelist('acciones_con_correo', ['Informes de cierre listos para Auditoria']);

        $this->flush();
    }

    public function down(): void
    {
        $now = now();
        $new = ['Informe de verificación del residente', 'Informes de cierre listos para Auditoria'];

        DB::table('notification_rules')->whereIn('action', $new)->delete();
        DB::table('notification_rules')->where('action', 'Liberacion de anticipo')->where('role', self::R)->delete();
        DB::table('notification_actions')->whereIn('key', $new)->delete();
        DB::table('notification_actions')->whereIn('key', self::RETIRED)->update(['is_active' => true, 'updated_at' => $now]);

        foreach (['acciones_con_notificacion_app', 'acciones_con_correo'] as $settingKey) {
            $setting = DB::table('app_settings')->where('key', $settingKey)->first();
            if ($setting !== null) {
                $remaining = array_values(array_diff(json_decode($setting->value, true) ?? [], $new));
                DB::table('app_settings')->where('key', $settingKey)->update(['value' => json_encode($remaining), 'updated_at' => $now]);
            }
        }

        $this->flush();
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

    private function flush(): void
    {
        NotificationCatalog::forget();
        SettingsService::forget();
        NotificationRuleResolver::forget();
    }
};
