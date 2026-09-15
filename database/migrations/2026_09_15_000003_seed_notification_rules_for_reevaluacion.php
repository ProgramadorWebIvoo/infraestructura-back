<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Registra 'Solicitud de reevaluación a Cierre de Obra' y 'Reevaluación
     * resuelta, reenviado a Procura' en notification_rules — sin esta fila
     * caían al fallback de NotificationRuleResolver::rolesFor() (solo
     * SUPERADMIN/ADMIN, canal app, sin mail, con Log::warning en cada
     * disparo) en vez de notificar a quien realmente debe actuar. Mismo
     * patrón que 2026_08_21_160000_seed_notification_rules_for_project_rejection.php.
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

        // Solicitud de reevaluación: Cierre de Obra debe corregir y reenviar
        // — mismo peso que 'Rechazo de petición de obra' (app + mail).
        $addRule('Solicitud de reevaluación a Cierre de Obra', ['CIERRE_DE_OBRA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Solicitud de reevaluación a Cierre de Obra', ['CIERRE_DE_OBRA', 'SUPERADMIN', 'ADMIN'], 'mail');

        // Reevaluación resuelta: Procura debe volver a autorizar inversión —
        // informativo, solo app.
        $addRule('Reevaluación resuelta, reenviado a Procura', ['PROCURA', 'SUPERADMIN', 'ADMIN'], 'app');

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
            if (!in_array('Solicitud de reevaluación a Cierre de Obra', $actions, true)) {
                $actions[] = 'Solicitud de reevaluación a Cierre de Obra';
                DB::table('app_settings')
                    ->where('key', 'acciones_con_correo')
                    ->update(['value' => json_encode($actions), 'updated_at' => $now]);
            }
        }
    }

    public function down(): void
    {
        DB::table('notification_rules')
            ->whereIn('action', ['Solicitud de reevaluación a Cierre de Obra', 'Reevaluación resuelta, reenviado a Procura'])
            ->delete();

        $setting = DB::table('app_settings')->where('key', 'acciones_con_correo')->first();
        if ($setting) {
            $actions = array_values(array_diff(json_decode($setting->value, true) ?? [], ['Solicitud de reevaluación a Cierre de Obra']));
            DB::table('app_settings')
                ->where('key', 'acciones_con_correo')
                ->update(['value' => json_encode($actions), 'updated_at' => now()]);
        }
    }
};
