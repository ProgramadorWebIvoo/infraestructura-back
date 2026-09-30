<?php

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * La tasa se congela SIEMPRE, en uno de los momentos del flujo: no existe la opción de no congelar.
 * Un valor NINGUNO (que dejó la migración de los toggles cuando todos estaban apagados) pasa al
 * primer momento: Procura solicita el anticipo a Finanzas (CONTRATADO).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('app_settings')
            ->where('key', 'congelar_tasa_momento')
            ->where('value', 'NINGUNO')
            ->update(['value' => 'CONTRATADO', 'updated_at' => now()]);

        SettingsService::forget();
    }

    /** Sin reversa: NINGUNO ya no es un valor válido. */
    public function down(): void
    {
    }
};
