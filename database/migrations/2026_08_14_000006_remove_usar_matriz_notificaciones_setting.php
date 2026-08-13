<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Deploy 5 (limpieza) del rollout de la matriz de notificaciones: una
     * vez verificada la equivalencia con `notifications:compare-recipients`
     * y activada la matriz como único camino, el flag deja de tener uso —
     * `NotificationDispatcher` ya no tiene rama legacy que alternar.
     */
    public function up(): void
    {
        DB::table('app_settings')->where('key', 'usar_matriz_notificaciones')->delete();
    }

    public function down(): void
    {
        $now = now();

        DB::table('app_settings')->insert([
            'group' => 'notificaciones',
            'key' => 'usar_matriz_notificaciones',
            'value' => 'true',
            'type' => 'boolean',
            'min_value' => null,
            'max_value' => null,
            'label' => 'Usar matriz de notificaciones por rol',
            'description' => 'Activa la resolución de destinatarios vía la matriz configurable (rol × acción × canal) en vez de la lógica fija anterior.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
