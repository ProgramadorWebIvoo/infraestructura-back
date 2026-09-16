<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Última sugerencia de rating IA por proveedor — poblada tanto por el batch
 * automático (RatingIaBatchService, ver rating-ia:run) como por la consulta
 * puntual ya existente (ContractorController::ratingSuggestion). Una fila por
 * contractor_code (upsert): a diferencia de exchange_rates, acá no interesa
 * el histórico de sugerencias, solo la más reciente para mostrarla en el
 * panel de Proveedores sin tener que volver a llamar a la IA.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contractor_rating_suggestions', function (Blueprint $table) {
            $table->id();
            $table->string('contractor_code', 30);
            $table->foreign('contractor_code')->references('code')->on('contractors')->cascadeOnDelete();
            $table->unique('contractor_code');

            $table->decimal('current_rating', 2, 1)->nullable();
            $table->decimal('suggested_rating', 2, 1)->nullable();
            $table->unsignedTinyInteger('confidence_score')->default(0);
            $table->text('rationale')->nullable();
            $table->string('provider', 50)->nullable();
            $table->enum('source', ['manual', 'batch'])->default('manual');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contractor_rating_suggestions');
    }
};
