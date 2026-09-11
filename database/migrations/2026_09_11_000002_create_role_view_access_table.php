<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acceso por defecto de cada rol a una vista — reemplaza el array de
 * config/permissions.php. `role` es un string libre igual que users.role
 * (ver app/Support/Roles.php, no hay tabla `roles`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('role_view_access', function (Blueprint $table) {
            $table->id();
            $table->string('role');
            $table->foreignId('view_definition_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['role', 'view_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_view_access');
    }
};
