<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Categorías del catálogo maestro. `spec_schema` define, por categoría,
     * qué características técnicas se piden/validan en el compendio de cada
     * línea de propuesta (cemento pide "resistencia_mpa", cable pide
     * "calibre_awg", etc.) — validado en la capa de aplicación, no en BD,
     * porque el set de campos varía libremente por categoría sin requerir
     * una migración de esquema cada vez que aparece un tipo de producto nuevo.
     */
    public function up(): void
    {
        Schema::create('catalog_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->foreignId('parent_id')->nullable()->constrained('catalog_categories')->nullOnDelete();
            $table->json('spec_schema')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_categories');
    }
};
