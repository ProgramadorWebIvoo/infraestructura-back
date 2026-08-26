<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * `polling_notificaciones_segundos` quedó huérfano tras migrar las
     * notificaciones de polling a WebSocket (Laravel Reverb): ya nada en el
     * frontend consulta este valor (NotificationsProvider ahora recibe
     * eventos por push, no encuestando al backend), pero la fila seguía
     * apareciendo en CONFIG APP sin efecto real — configurarla ya no cambia
     * ningún comportamiento de la app. `polling_dashboard_segundos` SÍ
     * sigue vigente (el dashboard de Presidencia sigue con polling propio,
     * fuera del alcance de esta migración) y no se toca.
     */
    public function up(): void
    {
        DB::table('app_settings')
            ->where('key', 'polling_notificaciones_segundos')
            ->delete();
    }

    public function down(): void
    {
        $now = now();

        DB::table('app_settings')->insert([
            'group' => 'notificaciones',
            'key' => 'polling_notificaciones_segundos',
            'value' => '8',
            'type' => 'integer',
            'min_value' => 5,
            'max_value' => 120,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
