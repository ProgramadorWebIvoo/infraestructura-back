<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Configuración del cronjob de RatingIA desde CONFIG APP → Proveedores,
 * mismo patrón que tasa_cambio_cron_* (ver
 * 2026_09_14_170000_add_exchange_rate_cron_settings.php) pero por DÍAS en
 * vez de por hora fija diaria: la frecuencia deseada es "cada N días", no
 * "todos los días a las X". Deshabilitado por defecto (a diferencia de
 * tasas): evaluar proveedores con IA tiene costo por token y no es crítico
 * para la operación diaria — que un SUPERADMIN lo active a conciencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Cada fila debe declarar exactamente las mismas columnas: un insert
        // multi-fila de Laravel arma la lista de columnas a partir de las
        // keys de la PRIMERA fila, así que una fila con menos keys que otra
        // (ej. sin min_value/max_value) desalinea los valores de TODAS las
        // filas siguientes contra las columnas equivocadas.
        DB::table('app_settings')->insert([
            [
                'group' => 'rating_ia',
                'key' => 'rating_ia_cron_habilitado',
                'value' => 'false',
                'type' => 'boolean',
                'min_value' => null,
                'max_value' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'rating_ia',
                'key' => 'rating_ia_cron_frecuencia_dias',
                'value' => '30',
                'type' => 'integer',
                'min_value' => 1,
                'max_value' => 180,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'rating_ia',
                'key' => 'rating_ia_cron_hora',
                'value' => '02:00',
                'type' => 'string',
                'min_value' => null,
                'max_value' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'rating_ia',
                'key' => 'rating_ia_debug',
                'value' => 'false',
                'type' => 'boolean',
                'min_value' => null,
                'max_value' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('app_settings')->whereIn('key', [
            'rating_ia_cron_habilitado',
            'rating_ia_cron_frecuencia_dias',
            'rating_ia_cron_hora',
            'rating_ia_debug',
        ])->delete();
    }
};
