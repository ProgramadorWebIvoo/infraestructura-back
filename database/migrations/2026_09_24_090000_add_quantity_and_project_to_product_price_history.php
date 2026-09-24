<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega `quantity` y `project_id` a product_price_history — faltaban para
 * el "Histórico de productos" de Fase 4 (fecha, producto, proveedor,
 * cantidad, precio, moneda, proyecto), base de la vista de inflación de
 * Fase 5. `project_id` se desnormaliza acá (en vez de resolverse en cada
 * request vía project_proposal_id → project_proposals, o vía
 * supplier_material_proposal_line_id → supplier_material_proposals) para
 * poder filtrar/agrupar histórico por proyecto sin joins en cada consulta
 * de serie temporal — mismo criterio que ya usa esta tabla para price_usd.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_history', function (Blueprint $table) {
            $table->decimal('quantity', 18, 4)->nullable()->after('supplier_code');
            $table->string('project_id', 40)->nullable()->after('origin');
            $table->foreign('project_id')->references('id')->on('projects')->nullOnDelete();
            $table->index(['catalog_product_id', 'project_id'], 'idx_price_history_product_project');
        });
    }

    public function down(): void
    {
        Schema::table('product_price_history', function (Blueprint $table) {
            $table->dropForeign(['project_id']);
            $table->dropIndex('idx_price_history_product_project');
            $table->dropColumn(['quantity', 'project_id']);
        });
    }
};
