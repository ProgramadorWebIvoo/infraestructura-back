<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Flag de activación de la matriz configurable rol×acción×canal
     * (`notification_rules`, vía NotificationRuleResolver). Empieza en
     * `false` — NotificationDispatcher sigue usando `recipientsFor()` legacy
     * hasta que el comando `notifications:compare-recipients` confirme
     * equivalencia exacta entre ambos caminos para las acciones
     * preexistentes (ver Fase D del plan de notificaciones configurables).
     */
    public function up(): void
    {
        $now = now();

        DB::table('app_settings')->insert([
            'group' => 'notificaciones',
            'key' => 'usar_matriz_notificaciones',
            'value' => 'false',
            'type' => 'boolean',
            'min_value' => null,
            'max_value' => null,
            'label' => 'Usar matriz de notificaciones por rol',
            'description' => 'Activa la resolución de destinatarios vía la matriz configurable (rol × acción × canal) en vez de la lógica fija anterior. No activar sin antes verificar con `notifications:compare-recipients`.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function down(): void
    {
        DB::table('app_settings')->where('key', 'usar_matriz_notificaciones')->delete();
    }
};
