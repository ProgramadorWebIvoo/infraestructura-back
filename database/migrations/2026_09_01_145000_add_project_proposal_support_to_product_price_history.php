<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extiende product_price_history para soportar propuestas de proyectos
 * (Analistas: MANUAL, RENEGOCIACION) además de propuestas del portal.
 *
 * Cambios:
 * 1. supplier_material_proposal_line_id: nullable (no existe en ProjectProposal)
 * 2. project_proposal_id: nuevo campo para rastrear origen en propuestas de Analistas
 * 3. origin: nuevo campo para distinguir PORTAL_PROV vs PROJECT_PROPOSAL
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_price_history', function (Blueprint $table) {
            // Hacer nullable el FK a SupplierMaterialProposalLine
            $table->dropForeign('fk_pph_proposal_line');
            $table->foreignId('supplier_material_proposal_line_id')
                ->nullable()
                ->change();
            $table->foreign('supplier_material_proposal_line_id', 'fk_pph_proposal_line')
                ->nullable()
                ->references('id')
                ->on('supplier_material_proposal_lines');

            // Agregar referencia a ProjectProposal
            $table->string('project_proposal_id', 40)->nullable()->after('supplier_material_proposal_line_id');
            $table->foreign('project_proposal_id')
                ->references('id')
                ->on('project_proposals')
                ->nullOnDelete();

            // Agregar campo origin para distinguir fuente
            $table->string('origin', 20)->default('PORTAL_PROV')->after('project_proposal_id');
            $table->index('origin');
        });
    }

    public function down(): void
    {
        Schema::table('product_price_history', function (Blueprint $table) {
            $table->dropForeignKey('fk_pph_proposal_line');
            $table->dropIndex('origin');
            $table->dropForeign(['project_proposal_id']);

            // Revertir nullable en supplier_material_proposal_line_id
            $table->foreignId('supplier_material_proposal_line_id')
                ->change();
            $table->foreign('supplier_material_proposal_line_id', 'fk_pph_proposal_line')
                ->references('id')
                ->on('supplier_material_proposal_lines');

            $table->dropColumn(['project_proposal_id', 'origin']);
        });
    }
};
