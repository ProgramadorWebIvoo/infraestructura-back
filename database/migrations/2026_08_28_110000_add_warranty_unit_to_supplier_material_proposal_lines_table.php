<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `warranty_months` asumía que toda garantía se mide en meses — no
     * refleja casos reales (garantías en días o años/temporadas). Se
     * renombra a `warranty_value` (numérico genérico) y se agrega
     * `warranty_unit` (dias/semanas/meses), mismo par valor+unidad que
     * `project_proposals.duration_value`/`duration_unit` para el plazo de
     * entrega — consistente con ese patrón ya establecido en el sistema.
     */
    public function up(): void
    {
        Schema::table('supplier_material_proposal_lines', function (Blueprint $table) {
            $table->renameColumn('warranty_months', 'warranty_value');
        });

        Schema::table('supplier_material_proposal_lines', function (Blueprint $table) {
            $table->string('warranty_unit', 10)->nullable()->after('warranty_value');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_material_proposal_lines', function (Blueprint $table) {
            $table->dropColumn('warranty_unit');
        });

        Schema::table('supplier_material_proposal_lines', function (Blueprint $table) {
            $table->renameColumn('warranty_value', 'warranty_months');
        });
    }
};
