<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Toggles de congelación de tasa de cambio, editables desde CONFIG APP —
 * grupo 'congelacion_tasa' (grupo 'moneda' quedó libre desde que se retiró
 * `moneda_base`, ver 2026_08_14_000010_drop_moneda_base_setting.php, pero se
 * usa un grupo propio y más descriptivo para no reciclar semántica vieja).
 * Default true: la congelación es el comportamiento seguro/esperado dado el
 * problema fiscal que resuelve — un SUPERADMIN puede desactivar cada trigger
 * individualmente si un flujo de negocio específico no la necesita.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('app_settings')->insert([
            [
                'group' => 'congelacion_tasa',
                'key' => 'congelar_tasa_en_contratacion',
                'value' => 'true',
                'type' => 'boolean',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'congelacion_tasa',
                'key' => 'congelar_tasa_en_pago_anticipo',
                'value' => 'true',
                'type' => 'boolean',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'congelacion_tasa',
                'key' => 'congelar_tasa_en_pago_finiquito',
                'value' => 'true',
                'type' => 'boolean',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);
    }

    public function down(): void
    {
        DB::table('app_settings')
            ->where('group', 'congelacion_tasa')
            ->whereIn('key', [
                'congelar_tasa_en_contratacion',
                'congelar_tasa_en_pago_anticipo',
                'congelar_tasa_en_pago_finiquito',
            ])
            ->delete();
    }
};
