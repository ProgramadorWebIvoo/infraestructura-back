<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Registra 'Rechazo de petición de obra' y 'Reenvío de petición
     * corregida' en notification_rules — sin esta fila caían al fallback
     * de NotificationRuleResolver::rolesFor() (solo SUPERADMIN/ADMIN, canal
     * app, sin mail, con Log::warning en cada disparo) en vez de notificar
     * a los roles que realmente necesitan actuar. Mismo patrón que
     * 2026_08_14_000005_seed_notification_rules.php.
     *
     * Idempotente vía upsert sobre el UNIQUE (action, role, channel).
     */
    public function up(): void
    {
        $now = now();
        $rows = [];

        $addRule = function (string $action, array $roles, string $channel) use (&$rows, $now) {
            foreach ($roles as $role) {
                $rows[] = [
                    'action' => $action,
                    'role' => $role,
                    'channel' => $channel,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        };

        // Rechazo: Infraestructura debe corregir y reenviar — mismo peso que
        // 'Rechazo de cuadro comparativo' (app + mail).
        $addRule('Rechazo de petición de obra', ['INFRAESTRUCTURA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Rechazo de petición de obra', ['INFRAESTRUCTURA', 'SUPERADMIN', 'ADMIN'], 'mail');

        // Reenvío: Cierre de Obra debe volver a revisarla — informativo, solo app.
        $addRule('Reenvío de petición corregida', ['CIERRE_DE_OBRA', 'SUPERADMIN', 'ADMIN'], 'app');

        DB::table('notification_rules')->upsert(
            $rows,
            ['action', 'role', 'channel'],
            ['enabled', 'updated_at'],
        );

        // acciones_con_correo es el gate separado que decide si una acción
        // dispara correo en absoluto (NotificationDispatcher::isMailActionAllowed).
        $setting = DB::table('app_settings')->where('key', 'acciones_con_correo')->first();
        if ($setting) {
            $actions = json_decode($setting->value, true) ?? [];
            if (!in_array('Rechazo de petición de obra', $actions, true)) {
                $actions[] = 'Rechazo de petición de obra';
                DB::table('app_settings')
                    ->where('key', 'acciones_con_correo')
                    ->update(['value' => json_encode($actions), 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('notification_rules')
            ->whereIn('action', ['Rechazo de petición de obra', 'Reenvío de petición corregida'])
            ->delete();

        $setting = DB::table('app_settings')->where('key', 'acciones_con_correo')->first();
        if ($setting) {
            $actions = array_values(array_diff(json_decode($setting->value, true) ?? [], ['Rechazo de petición de obra']));
            DB::table('app_settings')
                ->where('key', 'acciones_con_correo')
                ->update(['value' => json_encode($actions), 'updated_at' => now()]);
        }
    }
};
