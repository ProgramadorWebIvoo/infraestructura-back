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
        Schema::table('projects', function (Blueprint $table) {
            // Evaluación IA movida a cola (Job) para no bloquear el worker HTTP
            // hasta 180s (3 providers x 60s de failover) — ver
            // EvaluateProposalsWithAIJob. "processing" se fija antes de
            // encolar; el frontend hace poll/listen hasta "completed"/"failed".
            $table->string('bid_evaluation_ai_status', 20)->nullable()->after('bid_evaluation_ai_evaluated_at');
            $table->text('bid_evaluation_ai_error')->nullable()->after('bid_evaluation_ai_status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['bid_evaluation_ai_status', 'bid_evaluation_ai_error']);
        });
    }
};
