<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            // Reemplazo profesional-auditable de una renegociación: la
            // propuesta original NUNCA se borra ni se sobrescribe — queda
            // marcada con la propuesta que la reemplazó, fuera del cuadro
            // comparativo activo pero consultable para Presidencia/auditoría
            // (base para el futuro análisis inflacionario de productos).
            $table->string('replaced_by_id', 40)->nullable()->after('id');

            $table->foreign('replaced_by_id', 'fk_project_proposals_replaced_by')
                ->references('id')->on('project_proposals')
                ->onDelete('set null')->onUpdate('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('project_proposals', function (Blueprint $table) {
            $table->dropForeign('fk_project_proposals_replaced_by');
            $table->dropColumn('replaced_by_id');
        });
    }
};
