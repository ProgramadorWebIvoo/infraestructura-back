<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hace configurable desde CONFIG APP lo que hoy vive hardcodeado en
 * routes/console.php (hora fija '10:00', sin toggle) y agrega un modo debug
 * para diagnosticar fallos de sync (DolarVZLA API / BCV scraping) sin tener
 * que revisar storage/logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('app_settings')->insert([
            [
                'group' => 'sincronizacion_tasa',
                'key' => 'tasa_cambio_cron_hora',
                'value' => '10:00',
                'type' => 'string',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'sincronizacion_tasa',
                'key' => 'tasa_cambio_cron_habilitado',
                'value' => 'true',
                'type' => 'boolean',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'group' => 'sincronizacion_tasa',
                'key' => 'tasa_cambio_debug',
                'value' => 'false',
                'type' => 'boolean',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        Schema::table('exchange_rate_sync_logs', function (Blueprint $table) {
            $table->text('debug_details')->nullable()->after('error_message');
        });
    }

    public function down(): void
    {
        DB::table('app_settings')->whereIn('key', [
            'tasa_cambio_cron_hora',
            'tasa_cambio_cron_habilitado',
            'tasa_cambio_debug',
        ])->delete();

        Schema::table('exchange_rate_sync_logs', function (Blueprint $table) {
            $table->dropColumn('debug_details');
        });
    }
};
