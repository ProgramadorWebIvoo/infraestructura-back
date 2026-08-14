<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Catálogo de monedas aceptadas por la app — reemplaza a `moneda_base`
     * como string libre en `app_settings`. Los proveedores pueden ofrecer
     * precios en distintas monedas como tasas referenciales; la app necesita
     * saber cuáles están habilitadas y cuál es la base de registro. Tabla
     * dedicada (no JSON en AppSetting) porque es un catálogo con su propio
     * ciclo de vida — activar/desactivar monedas — igual que
     * `notification_rules` antes que esto.
     *
     * Exactamente una fila puede tener `is_base = true` en cualquier
     * momento, forzado en la capa de aplicación (CurrencyController), no en
     * el esquema.
     */
    public function up(): void
    {
        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3);
            $table->string('name', 80);
            $table->string('symbol', 8);
            $table->boolean('is_base')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique('code');
        });

        DB::table('currencies')->insert([
            'code' => 'USD',
            'name' => 'Dólar estadounidense',
            'symbol' => '$',
            'is_base' => true,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('currencies');
    }
};
