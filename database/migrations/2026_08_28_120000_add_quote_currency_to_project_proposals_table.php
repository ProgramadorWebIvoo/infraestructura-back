<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            // Moneda en la que el proveedor cotizó la propuesta original (si
            // vino del portal público) — se conserva al importar para no
            // perder el contexto en el detalle que ve Analistas/Procura.
            // Nula en propuestas de carga manual (siempre se cargan en USD).
            $table->string('quote_currency', 3)->nullable()->after('material_items');
        });
    }

    public function down(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->dropColumn('quote_currency');
        });
    }
};
