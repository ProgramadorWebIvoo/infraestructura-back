<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('bid_evaluation_ai_winner_code', 50)->nullable()->after('dossier_ai_evaluated_at');
            $table->string('bid_evaluation_ai_winner_name', 500)->nullable()->after('bid_evaluation_ai_winner_code');
            $table->unsignedTinyInteger('bid_evaluation_ai_confidence_score')->nullable()->after('bid_evaluation_ai_winner_name');
            $table->text('bid_evaluation_ai_summary')->nullable()->after('bid_evaluation_ai_confidence_score');
            $table->json('bid_evaluation_ai_strengths')->nullable()->after('bid_evaluation_ai_summary');
            $table->json('bid_evaluation_ai_weaknesses')->nullable()->after('bid_evaluation_ai_strengths');
            $table->json('bid_evaluation_ai_risk_factors')->nullable()->after('bid_evaluation_ai_weaknesses');
            $table->text('bid_evaluation_ai_recommendation')->nullable()->after('bid_evaluation_ai_risk_factors');
            $table->string('bid_evaluation_ai_provider', 20)->nullable()->after('bid_evaluation_ai_recommendation');
            $table->timestamp('bid_evaluation_ai_evaluated_at')->nullable()->after('bid_evaluation_ai_provider');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'bid_evaluation_ai_winner_code',
                'bid_evaluation_ai_winner_name',
                'bid_evaluation_ai_confidence_score',
                'bid_evaluation_ai_summary',
                'bid_evaluation_ai_strengths',
                'bid_evaluation_ai_weaknesses',
                'bid_evaluation_ai_risk_factors',
                'bid_evaluation_ai_recommendation',
                'bid_evaluation_ai_provider',
                'bid_evaluation_ai_evaluated_at',
            ]);
        });
    }
};
