<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_material_proposals', function (Blueprint $table) {
            $table->string('id', 40)->primary();
            $table->string('project_id', 40);
            $table->string('project_title_snapshot', 220);
            $table->string('supplier_name', 180);
            $table->string('supplier_company', 180)->nullable();
            $table->string('supplier_contact', 180);
            $table->char('invitation_token', 36)->nullable();
            $table->json('items');
            $table->text('general_notes')->nullable();
            $table->unsignedSmallInteger('estimated_days')->nullable();
            $table->string('duration_unit', 20)->nullable();
            $table->timestamp('submitted_at')->useCurrent();

            $table->index('project_id');
            $table->index('invitation_token');
            $table->index('submitted_at');

            $table->foreign('project_id')
                ->references('id')
                ->on('projects')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_material_proposals');
    }
};
