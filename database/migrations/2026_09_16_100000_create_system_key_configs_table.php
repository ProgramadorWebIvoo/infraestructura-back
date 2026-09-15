<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciales de infraestructura (SMTP, Pusher, ...) editables desde
 * Configuración > Configuración de Keys, para no depender de tocar .env y
 * redeployar cada vez que cambian. `data` guarda el payload completo del
 * grupo (todos los campos del formulario) cifrado con el cast `encrypted`
 * de Laravel — igual criterio que `ai_configurations.api_key`, pero acá se
 * cifra el JSON entero porque el "secreto" no es un único campo sino varios
 * (host/usuario/password de SMTP, key/secret de Pusher).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_key_configs', function (Blueprint $table) {
            $table->id();
            $table->string('group', 50)->unique(); // 'smtp', 'pusher'
            $table->text('data')->nullable();       // encrypted JSON
            $table->boolean('is_active')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_key_configs');
    }
};
