<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Siembra las reglas de notificación (canal app) para las acciones del
     * módulo Marketing — sin esto, NotificationRuleResolver cae al fallback
     * SUPERADMIN/ADMIN y marca la acción como "sin configurar" en el banner
     * de CONFIG APP (mismo motivo que las siembras previas de este patrón).
     * MARKETING se notifica de aprobaciones/rechazos de SUS propias
     * propuestas; SUPERADMIN/ADMIN se notifican de todo el flujo.
     */
    public function up(): void
    {
        $now = now();
        $rows = [];

        $addRule = function (string $action, array $roles) use (&$rows, $now) {
            foreach ($roles as $role) {
                $rows[] = [
                    'action' => $action,
                    'role' => $role,
                    'channel' => 'app',
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        };

        $addRule('Creacion de propuesta de marketing', ['SUPERADMIN', 'ADMIN']);
        $addRule('Envio a revision de propuesta de marketing', ['SUPERADMIN', 'ADMIN']);
        $addRule('Aprobacion de propuesta de marketing', ['MARKETING', 'SUPERADMIN', 'ADMIN']);
        $addRule('Rechazo de propuesta de marketing', ['MARKETING', 'SUPERADMIN', 'ADMIN']);
        $addRule('Modificacion de propuesta de marketing', ['SUPERADMIN', 'ADMIN']);
        $addRule('Eliminacion de propuesta de marketing', ['SUPERADMIN', 'ADMIN']);
        $addRule('Carga de adjunto de marketing', ['SUPERADMIN', 'ADMIN']);
        $addRule('Eliminacion de adjunto de marketing', ['SUPERADMIN', 'ADMIN']);

        DB::table('notification_rules')->upsert(
            $rows,
            ['action', 'role', 'channel'],
            ['enabled', 'updated_at']
        );
    }

    public function down(): void
    {
        DB::table('notification_rules')->whereIn('action', [
            'Creacion de propuesta de marketing',
            'Envio a revision de propuesta de marketing',
            'Aprobacion de propuesta de marketing',
            'Rechazo de propuesta de marketing',
            'Modificacion de propuesta de marketing',
            'Eliminacion de propuesta de marketing',
            'Carga de adjunto de marketing',
            'Eliminacion de adjunto de marketing',
        ])->delete();
    }
};
