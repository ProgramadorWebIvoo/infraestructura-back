<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Costo de mano de obra opcional, a nivel de pedido completo (no por
     * línea) — mismo criterio que `advance_percent`. Análogo a
     * `project_proposals.labor_cost` (propuestas internas de Analistas),
     * que también es un total, no un desglose por material.
     */
    public function up(): void
    {
        Schema::table('supplier_material_proposals', function (Blueprint $table) {
            $table->decimal('labor_cost', 14, 2)->nullable()->after('advance_percent');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_material_proposals', function (Blueprint $table) {
            $table->dropColumn('labor_cost');
        });
    }
};
