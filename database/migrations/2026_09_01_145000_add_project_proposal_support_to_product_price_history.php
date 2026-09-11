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
            // Hacer nullable el FK a SupplierMaterialProposalLine.
            // SQLite (usado en tests) no soporta dropForeign() por nombre —
            // Doctrine DBAL recrea la tabla al hacer ->change(), preservando
            // la FK "fk_pph_proposal_line" existente automáticamente. En
            // MySQL/Postgres (producción) ->change() también altera la
            // columna in-place sin tocar la FK. Ver 2026_08_13_000003 para
            // el mismo patrón.
            $table->foreignId('supplier_material_proposal_line_id')
                ->nullable()
                ->change();

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
            // dropForeign/dropIndex por array de columnas (no por nombre) es
            // lo único compatible con SQLite — ver comentario en up().
            $table->dropForeign(['project_proposal_id']);
            $table->dropIndex(['origin']);
            $table->dropColumn(['project_proposal_id', 'origin']);

            // Revertir nullable en supplier_material_proposal_line_id,
            // preservando la FK existente igual que en up().
            $table->foreignId('supplier_material_proposal_line_id')
                ->change();
        });
    }
};
