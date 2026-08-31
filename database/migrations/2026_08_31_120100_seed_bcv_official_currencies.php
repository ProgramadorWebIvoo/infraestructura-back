<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Siembra EUR como moneda oficial del BCV y marca USD (ya existente
     * desde la creación de `currencies`) como oficial también. `upsert` por
     * `code` para que sea idempotente si ya existiera una fila EUR cargada
     * manualmente antes de esta migración (ej. un SUPERADMIN que ya la
     * agregó a mano como moneda "custom"): esta migración la adopta como
     * oficial en vez de fallar por duplicado.
     */
    public function up(): void
    {
        $now = now();

        DB::table('currencies')->upsert([
            ['code' => 'USD', 'name' => 'Dólar estadounidense', 'symbol' => '$', 'is_official' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_official' => true, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
        ], ['code'], ['name', 'symbol', 'is_official', 'is_active', 'updated_at']);
    }

    /**
     * Solo revierte `is_official` de EUR y USD (no las borra: podrían tener
     * exchange_rates o proposal lines referenciándolas por FK) — nunca
     * desactiva/elimina, para no romper datos ya en uso.
     */
    public function down(): void
    {
        DB::table('currencies')
            ->whereIn('code', ['EUR', 'USD'])
            ->update(['is_official' => false]);
    }
};
