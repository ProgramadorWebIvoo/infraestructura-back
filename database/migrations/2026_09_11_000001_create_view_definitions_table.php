<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de vistas SPA accesibles (reemplaza las claves hardcodeadas de
 * config/permissions.php, ahora fuente editable desde el panel de Usuarios).
 * `key` es el path de ROUTES.* en el frontend (ej. '/procura').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('view_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('label');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('view_definitions');
    }
};
