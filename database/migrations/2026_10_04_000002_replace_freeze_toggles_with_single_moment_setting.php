<?php

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Congelación de tasa" deja de ser tres checkboxes independientes y pasa a
 * ser UNA sola opción (radio): en qué momento del flujo se congelan los Bs.
 * de la obra. Valores: CONTRATADO | PAGO_ANTICIPO | PAGO_FINIQUITO | NINGUNO.
 *
 * Migración del valor: gana el primer toggle activo en orden de flujo
 * (contratación, anticipo, finiquito); si ninguno estaba activo, NINGUNO.
 * Con los tres activos (el valor por defecto de antes) queda CONTRATADO.
 */
return new class extends Migration
{
    private const OLD_TOGGLES = [
        'congelar_tasa_en_contratacion' => 'CONTRATADO',
        'congelar_tasa_en_pago_anticipo' => 'PAGO_ANTICIPO',
        'congelar_tasa_en_pago_finiquito' => 'PAGO_FINIQUITO',
    ];

    public function up(): void
    {
        $moment = 'NINGUNO';
        foreach (self::OLD_TOGGLES as $key => $trigger) {
            $value = DB::table('app_settings')->where('key', $key)->value('value');
            // Sin fila = sin configurar = activo (el valor por defecto del servicio era true).
            if ($value === null || $value === 'true') {
                $moment = $trigger;
                break;
            }
        }

        $now = now();
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'congelar_tasa_momento'],
            ['group' => 'congelacion_tasa', 'value' => $moment, 'type' => 'string', 'min_value' => null, 'max_value' => null, 'created_at' => $now, 'updated_at' => $now],
        );

        DB::table('app_settings')->whereIn('key', array_keys(self::OLD_TOGGLES))->delete();

        SettingsService::forget();
    }

    public function down(): void
    {
        $moment = DB::table('app_settings')->where('key', 'congelar_tasa_momento')->value('value');
        $now = now();

        foreach (self::OLD_TOGGLES as $key => $trigger) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                ['group' => 'congelacion_tasa', 'value' => $moment === $trigger ? 'true' : 'false', 'type' => 'boolean', 'min_value' => null, 'max_value' => null, 'created_at' => $now, 'updated_at' => $now],
            );
        }

        DB::table('app_settings')->where('key', 'congelar_tasa_momento')->delete();

        SettingsService::forget();
    }
};
