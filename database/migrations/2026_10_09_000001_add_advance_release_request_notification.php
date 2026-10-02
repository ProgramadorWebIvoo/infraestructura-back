<?php

use App\Services\NotificationRuleResolver;
use App\Services\SettingsService;
use App\Support\NotificationCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Aviso propio para Finanzas cuando Procura envía una adjudicación (anticipo por
 * liberar). Antes solo recibía "Confirmación de contratación", un evento ya
 * consumado (tipo éxito) que comparten Presidencia y el solicitante, sin señal de
 * que Finanzas tiene una tarea. Es el equivalente de "Solicitud de pago de
 * finiquito"; los destinatarios quedan editables en Configuración > Notificaciones.
 */
return new class extends Migration
{
    private const ACTION = 'Solicitud de liberacion de anticipo';
    private const LABEL = 'Solicitud de liberación de anticipo';

    private const APP_ROLES = ['FINANZAS', 'SUPERADMIN', 'ADMIN'];
    private const MAIL_ROLES = ['FINANZAS'];

    public function up(): void
    {
        $now = now();

        DB::table('notification_actions')->updateOrInsert(
            ['key' => self::ACTION],
            ['label' => self::LABEL, 'group' => 'proyectos', 'scope' => 'project', 'critical' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        );

        DB::table('notification_rules')->where('action', self::ACTION)->delete();
        $rows = [];
        foreach (['app' => self::APP_ROLES, 'mail' => self::MAIL_ROLES] as $channel => $roles) {
            foreach ($roles as $role) {
                $rows[] = ['action' => self::ACTION, 'role' => $role, 'channel' => $channel, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now];
            }
        }
        DB::table('notification_rules')->insert($rows);

        $this->addToWhitelist('acciones_con_notificacion_app');
        $this->addToWhitelist('acciones_con_correo');

        $this->flush();
    }

    public function down(): void
    {
        DB::table('notification_rules')->where('action', self::ACTION)->delete();
        DB::table('notification_actions')->where('key', self::ACTION)->delete();

        foreach (['acciones_con_notificacion_app', 'acciones_con_correo'] as $settingKey) {
            $setting = DB::table('app_settings')->where('key', $settingKey)->first();
            if ($setting !== null) {
                $remaining = array_values(array_diff(json_decode($setting->value, true) ?? [], [self::ACTION]));
                DB::table('app_settings')->where('key', $settingKey)->update(['value' => json_encode($remaining), 'updated_at' => now()]);
            }
        }

        $this->flush();
    }

    private function addToWhitelist(string $settingKey): void
    {
        $setting = DB::table('app_settings')->where('key', $settingKey)->first();
        if ($setting === null) {
            return;
        }
        $current = json_decode($setting->value, true) ?? [];
        if (!in_array(self::ACTION, $current, true)) {
            DB::table('app_settings')->where('key', $settingKey)->update(['value' => json_encode([...$current, self::ACTION]), 'updated_at' => now()]);
        }
    }

    private function flush(): void
    {
        NotificationCatalog::forget();
        SettingsService::forget();
        NotificationRuleResolver::forget();
    }
};
