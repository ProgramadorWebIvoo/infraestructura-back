<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * F3 — Modificaciones de obra: solicitud con N líneas (aumento o disminución por
 * partida) sobre una obra en ejecución. No altera el estado de la obra.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('project_modification_requests', function (Blueprint $table) {
            $table->id();
            $table->string('project_id', 40);
            $table->unsignedBigInteger('requested_by_user_id')->nullable();
            $table->string('status', 20)->default('PENDIENTE');
            $table->text('reason');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamps();

            $table->foreign('project_id', 'fk_mod_requests_project')->references('id')->on('projects')->cascadeOnDelete();
            $table->foreign('requested_by_user_id', 'fk_mod_requests_requester')->references('id')->on('users')->nullOnDelete();
            $table->foreign('reviewed_by_user_id', 'fk_mod_requests_reviewer')->references('id')->on('users')->nullOnDelete();
            $table->index(['project_id', 'status'], 'idx_mod_requests_project_status');
        });

        Schema::create('project_modification_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('request_id');
            $table->string('project_material_id', 40);
            $table->string('type', 12);
            $table->decimal('quantity', 14, 2);
            $table->decimal('unit_price_usd', 14, 4)->default(0);
            $table->text('note')->nullable();
            $table->timestamps();

            $table->foreign('request_id', 'fk_mod_items_request')->references('id')->on('project_modification_requests')->cascadeOnDelete();
            $table->foreign('project_material_id', 'fk_mod_items_material')->references('id')->on('project_materials')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_modification_items');
        Schema::dropIfExists('project_modification_requests');
    }
};
