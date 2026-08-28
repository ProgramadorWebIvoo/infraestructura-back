<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * projects.selected_proposal_id tenía un índice con nombre engañoso
 * ("fk_projects_selected_proposal") pero SIN constraint de foreign key real
 * — permitía guardar IDs de propuestas inexistentes sin que la BD lo
 * detectara. Verificado en producción: 0 registros huérfanos antes de
 * aplicar esta migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('selected_proposal_id', 'fk_projects_selected_proposal_real')
                ->references('id')->on('project_proposals')
                ->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign('fk_projects_selected_proposal_real');
        });
    }
};
