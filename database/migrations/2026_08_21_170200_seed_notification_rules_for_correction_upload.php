<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Registra 'Carga de correcciones de peticion rechazada' en
     * notification_rules — sin esta fila caía al fallback de
     * NotificationRuleResolver (solo SUPERADMIN/ADMIN, canal app, sin mail,
     * con Log::warning en cada disparo). Infraestructura es quien debe
     * enterarse: ya recibe 'Rechazo de petición de obra', esta es la
     * continuación cuando Cierre de Obra adjunta el archivo de corrección.
     * Mismo patrón que 2026_08_21_160000_seed_notification_rules_for_project_rejection.php.
     */
    public function up(): void
    {
        $now = now();
        $rows = [];

        foreach (['INFRAESTRUCTURA', 'SUPERADMIN', 'ADMIN'] as $role) {
            $rows[] = [
                'action' => 'Carga de correcciones de peticion rechazada',
                'role' => $role,
                'channel' => 'app',
                'enabled' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('notification_rules')->upsert(
            $rows,
            ['action', 'role', 'channel'],
            ['enabled', 'updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('notification_rules')
            ->where('action', 'Carga de correcciones de peticion rechazada')
            ->delete();
    }
};
