<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Siembra USDT (tasa paralela, no oficial del BCV). `is_official = false`
     * porque no viene del BCV; su protección contra borrado se aplica por
     * código en CurrencyController. `is_active` la habilita: si un
     * SUPERADMIN la desactiva, deja de sincronizarse y de mostrarse.
     */
    public function up(): void
    {
        $now = now();

        DB::table('currencies')->upsert([
            ['code' => 'USDT', 'name' => 'Tether (USDT)', 'symbol' => '₮', 'is_base' => false, 'is_official' => false, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'symbol', 'updated_at']);
    }

    /** No borra la fila: puede tener tasas en `exchange_rates` referenciándola por FK. */
    public function down(): void
    {
        DB::table('currencies')->where('code', 'USDT')->update(['is_active' => false]);
    }
};
