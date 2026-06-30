<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Resolves the circular FK: projects.selected_proposal_id -> project_proposals(id)
    // Applied after both tables exist.
    public function up()
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->foreign('selected_proposal_id', 'fk_projects_selected_proposal')
                ->references('id')->on('project_proposals')
                ->onDelete('set null')->onUpdate('cascade');
        });
    }

    public function down()
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropForeign('fk_projects_selected_proposal');
        });
    }
};
