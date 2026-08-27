<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Reclasificación administrativa de una línea "producto personalizado"
     * hacia un producto de catálogo existente, hecha por Presidencia después
     * del submit. No reescribe la línea original ni el
     * product_price_history ya grabado con el catalog_product_id resuelto
     * en su momento — es una decisión posterior, auditable por separado,
     * que no altera hechos históricos ya persistidos.
     */
    public function up(): void
    {
        Schema::create('custom_product_resolutions', function (Blueprint $table) {
            $table->foreignId('supplier_material_proposal_line_id')->primary();
            $table->foreign('supplier_material_proposal_line_id', 'fk_cpr_proposal_line')
                ->references('id')->on('supplier_material_proposal_lines')
                ->cascadeOnDelete();
            $table->foreignId('resolved_catalog_product_id')->constrained('material_catalog');
            $table->foreignId('resolved_by')->constrained('users');
            $table->timestamp('resolved_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_product_resolutions');
    }
};
