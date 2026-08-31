<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `is_official` marca las monedas del catálogo BCV (EUR, CNY, TRY, RUB,
     * USD) como estructuralmente inmutables: no editable name/symbol, no
     * eliminable, no duplicable — a diferencia de una moneda "custom" que
     * cualquier SUPERADMIN puede crear/editar/borrar libremente hoy. Solo
     * `is_active` queda editable en una moneda oficial (desactivarla detiene
     * la búsqueda diaria de tasa, sin borrar su histórico en exchange_rates).
     * La restricción real vive en la capa de aplicación (CurrencyController),
     * esta columna es solo el dato que la habilita.
     */
    public function up(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->boolean('is_official')->default(false)->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('currencies', function (Blueprint $table) {
            $table->dropColumn('is_official');
        });
    }
};
