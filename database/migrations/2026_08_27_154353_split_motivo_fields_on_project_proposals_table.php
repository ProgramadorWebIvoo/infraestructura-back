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
            // `motivo` (existente) queda dedicado exclusivamente a "por qué
            // se renegoció" (origen RENEGOCIACION). Antes del split se
            // reusaba también para justificar un anticipo negociado por
            // encima del máximo configurado en CONFIG APP — son excepciones
            // conceptualmente distintas (una explica un reemplazo de oferta,
            // la otra una desviación de política de anticipo) y pueden darse
            // a la vez en el mismo registro, así que necesitan columnas
            // separadas para quedar ambas auditables sin pisarse.
            $table->text('motivo_anticipo_excedido')->nullable()->after('motivo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->dropColumn('motivo_anticipo_excedido');
        });
    }
};
