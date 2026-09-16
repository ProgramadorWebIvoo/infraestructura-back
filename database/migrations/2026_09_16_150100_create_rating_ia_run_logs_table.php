<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de corridas del batch de RatingIA (rating-ia:run) — mismo rol que
 * exchange_rate_sync_logs: permite ver en el panel cuándo corrió por última
 * vez, cuántos proveedores evaluó y si hubo errores, sin tener que revisar
 * storage/logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rating_ia_run_logs', function (Blueprint $table) {
            $table->id();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('contractors_evaluated')->default(0);
            $table->unsignedInteger('suggestions_generated')->default(0);
            $table->unsignedInteger('errors_count')->default(0);
            $table->enum('status', ['success', 'partial', 'failed'])->default('success');
            $table->text('error_message')->nullable();
            $table->text('debug_details')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rating_ia_run_logs');
    }
};
