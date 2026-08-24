<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->unsignedTinyInteger('dossier_ai_score')->nullable()->after('blueprints_count');
            $table->text('dossier_ai_summary')->nullable()->after('dossier_ai_score');
            $table->json('dossier_ai_alerts')->nullable()->after('dossier_ai_summary');
            $table->text('dossier_ai_recommendation')->nullable()->after('dossier_ai_alerts');
            $table->decimal('dossier_ai_suggested_amount', 14, 2)->nullable()->after('dossier_ai_recommendation');
            $table->json('dossier_ai_completeness_factors')->nullable()->after('dossier_ai_suggested_amount');
            $table->string('dossier_ai_provider', 20)->nullable()->after('dossier_ai_completeness_factors');
            $table->timestamp('dossier_ai_evaluated_at')->nullable()->after('dossier_ai_provider');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn([
                'dossier_ai_score',
                'dossier_ai_summary',
                'dossier_ai_alerts',
                'dossier_ai_recommendation',
                'dossier_ai_suggested_amount',
                'dossier_ai_completeness_factors',
                'dossier_ai_provider',
                'dossier_ai_evaluated_at',
            ]);
        });
    }
};
