<?php

use App\Services\SettingsService;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Modo de las claves de idempotencia (PLAN-Idempotencia), editable desde CONFIG APP
 * para pasar de `log` a `enforce` sin redeploy (con `config:cache` el .env no basta).
 * Arranca en `log`: aplica replay/409/422 a quien envíe clave y solo registra a quien no.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('app_settings')->insert([
            'group' => 'app',
            'key' => 'idempotencia_modo',
            'value' => 'log',
            'type' => 'string',
            'min_value' => null,
            'max_value' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        SettingsService::forget();
    }

    public function down(): void
    {
        DB::table('app_settings')->where('key', 'idempotencia_modo')->delete();

        SettingsService::forget();
    }
};
