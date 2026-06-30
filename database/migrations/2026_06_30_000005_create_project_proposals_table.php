<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('project_proposals', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('project_id', 40);
            $table->string('contractor_code', 30);
            $table->string('contractor_name_snapshot', 180);
            $table->decimal('material_cost', 14, 2)->default(0.00);
            $table->decimal('labor_cost', 14, 2)->default(0.00);
            $table->decimal('total_cost', 14, 2)->default(0.00);
            $table->unsignedInteger('delivery_weeks')->default(0);
            $table->decimal('negotiated_advance_percent', 5, 2)->default(0.00);
            $table->text('description');
            $table->timestamp('created_at')->useCurrent();

            $table->index('project_id', 'idx_project_proposals_project');
            $table->index('contractor_code', 'idx_project_proposals_contractor');

            $table->foreign('project_id', 'fk_project_proposals_project')
                ->references('id')->on('projects')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign('contractor_code', 'fk_project_proposals_contractor')
                ->references('code')->on('contractors')
                ->onUpdate('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('project_proposals');
    }
};
