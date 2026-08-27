<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            // Detalle de materiales cargado línea por línea (nombre, cantidad,
            // unidad, precio unitario, notas) — igual al portal público del
            // proveedor, en vez de un único total. material_cost se sigue
            // guardando como columna derivada (suma) para no romper los
            // reportes/ordenamientos existentes que ya la usan.
            $table->json('material_items')->nullable()->after('material_cost');

            // Reemplaza delivery_weeks (fijo en semanas) por un valor +
            // unidad flexible. delivery_weeks se mantiene y se sigue
            // derivando desde duration_value/duration_unit para no romper
            // el comparativo existente que ordena/filtra por semanas.
            $table->unsignedInteger('duration_value')->nullable()->after('delivery_weeks');
            $table->string('duration_unit', 10)->nullable()->after('duration_value');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->dropColumn(['material_items', 'duration_value', 'duration_unit']);
        });
    }
};
