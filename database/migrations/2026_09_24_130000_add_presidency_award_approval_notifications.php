<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Circuito Procura → Presidencia → Procura → Finanzas: registra las acciones
 * nuevas y sus destinatarios. Sin filas en notification_rules el resolver
 * solo notificaría a SUPERADMIN/ADMIN.
 */
return new class extends Migration
{
    private const ACTIONS = [
        'Seleccion de contratista pendiente de Presidencia' => ['critical' => true,  'app' => ['PRESIDENCIA', 'SUPERADMIN', 'ADMIN'], 'mail' => ['PRESIDENCIA']],
        'Aprobacion de adjudicacion por Presidencia'        => ['critical' => false, 'app' => ['PROCURA', 'SUPERADMIN', 'ADMIN'],      'mail' => []],
        'Rechazo de adjudicacion por Presidencia'           => ['critical' => true,  'app' => ['PROCURA', 'SUPERADMIN', 'ADMIN'],      'mail' => ['PROCURA']],
    ];

    public function up(): void
    {
        $now = now();
        $rules = [];

        foreach (self::ACTIONS as $action => $config) {
            DB::table('notification_actions')->updateOrInsert(
                ['key' => $action],
                [
                    'label' => null,
                    'group' => 'proyectos',
                    'scope' => 'project',
                    'critical' => $config['critical'],
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ],
            );

            foreach (['app', 'mail'] as $channel) {
                foreach ($config[$channel] as $role) {
                    $rules[] = ['action' => $action, 'role' => $role, 'channel' => $channel, 'enabled' => true, 'created_at' => $now, 'updated_at' => $now];
                }
            }
        }

        DB::table('notification_rules')->upsert($rules, ['action', 'role', 'channel'], ['enabled', 'updated_at']);

        // 'acciones_con_notificacion_app' es una whitelist: sin esto las acciones
        // nuevas se auditan pero nunca disparan push/bandeja interna.
        $setting = DB::table('app_settings')->where('key', 'acciones_con_notificacion_app')->first();
        if ($setting !== null) {
            $current = json_decode($setting->value, true) ?? [];
            $missing = array_values(array_diff(array_keys(self::ACTIONS), $current));
            if ($missing) {
                DB::table('app_settings')->where('key', 'acciones_con_notificacion_app')
                    ->update(['value' => json_encode([...$current, ...$missing]), 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        $keys = array_keys(self::ACTIONS);

        DB::table('notification_rules')->whereIn('action', $keys)->delete();
        DB::table('notification_actions')->whereIn('key', $keys)->delete();

        $setting = DB::table('app_settings')->where('key', 'acciones_con_notificacion_app')->first();
        if ($setting !== null) {
            $remaining = array_values(array_diff(json_decode($setting->value, true) ?? [], $keys));
            DB::table('app_settings')->where('key', 'acciones_con_notificacion_app')
                ->update(['value' => json_encode($remaining), 'updated_at' => now()]);
        }
    }
};
