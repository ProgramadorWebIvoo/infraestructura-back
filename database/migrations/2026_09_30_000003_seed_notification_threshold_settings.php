<?php

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Umbrales de alertas automáticas que estaban fijos en el código: aviso de
 * invitación por vencer (48 h) y racha de rechazos consecutivos (3). Mismos
 * valores por defecto, ahora editables en CONFIG APP > Notificaciones.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('app_settings')->insert([
            [
                'group' => 'notificaciones',
                'key' => 'invitacion_aviso_horas',
                'value' => '48',
                'type' => 'integer',
                'min_value' => 1,
                'max_value' => 168,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'notificaciones',
                'key' => 'rechazos_consecutivos_alerta',
                'value' => '3',
                'type' => 'integer',
                'min_value' => 2,
                'max_value' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        SettingsService::forget();
    }

    public function down(): void
    {
        DB::table('app_settings')->whereIn('key', ['invitacion_aviso_horas', 'rechazos_consecutivos_alerta'])->delete();

        SettingsService::forget();
    }
};
