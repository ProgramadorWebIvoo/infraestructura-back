<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('project_materials', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('project_id', 40);
            $table->unsignedBigInteger('material_catalog_id')->nullable();
            $table->string('name', 180);
            $table->decimal('quantity', 14, 2)->default(0.00);
            $table->string('unit', 80);
            $table->decimal('estimated_unit_price', 14, 2)->default(0.00);
            $table->timestamp('created_at')->useCurrent();

            $table->index('project_id', 'idx_project_materials_project');
            $table->index('material_catalog_id', 'idx_project_materials_catalog');

            $table->foreign('project_id', 'fk_project_materials_project')
                ->references('id')->on('projects')
                ->onDelete('cascade')->onUpdate('cascade');

            $table->foreign('material_catalog_id', 'fk_project_materials_catalog')
                ->references('id')->on('material_catalog')
                ->onDelete('set null')->onUpdate('cascade');
        });
    }

    public function down()
    {
        Schema::dropIfExists('project_materials');
    }
};
