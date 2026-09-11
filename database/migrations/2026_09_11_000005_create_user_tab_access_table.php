<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override de visibilidad de tab por usuario — mismo patrón allow/deny que
 * user_view_access, resuelto contra tab_definitions.default_active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_tab_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tab_definition_id')->constrained()->cascadeOnDelete();
            $table->boolean('allowed');
            $table->timestamps();
            $table->unique(['user_id', 'tab_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_tab_access');
    }
};
