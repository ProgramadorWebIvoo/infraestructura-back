<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Override de acceso a vistas por usuario individual — se mezcla sobre el
 * default de su rol (role_view_access) en AccessResolver::resolveViews().
 * allowed=true fuerza acceso aunque el rol no lo tenga; allowed=false
 * revoca el acceso aunque el rol sí lo tenga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_view_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('view_definition_id')->constrained()->cascadeOnDelete();
            $table->boolean('allowed');
            $table->timestamps();
            $table->unique(['user_id', 'view_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_view_access');
    }
};
