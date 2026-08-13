<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Matriz configurable rol × acción × canal — reemplaza a
     * NotificationDispatcher::recipientsFor(), que hoy resuelve
     * destinatarios por un match($status) fijo de 9 ramas, indexado por el
     * ESTADO del proyecto en vez de la ACCIÓN. Esto impide configurar "quién
     * se entera de los rechazos" de forma independiente de otras acciones en
     * el mismo estado, y no tiene dónde encajar un flujo nuevo sin proyecto.
     *
     * Tabla dedicada en vez de un AppSetting JSON: con ~35 acciones un blob
     * único rompería el límite de validación de /settings, no permitiría
     * auditar cambios celda por celda, y generaría choques de escritura
     * entre SUPERADMIN concurrentes.
     *
     * Sin FK a un catálogo de acciones en BD (vive en código,
     * App\Support\NotificationCatalog) — la validación de `action`/`role`
     * ocurre en la capa de aplicación (NotificationRuleController), igual
     * que ya hace UserController con Roles::VALID.
     */
    public function up(): void
    {
        Schema::create('notification_rules', function (Blueprint $table) {
            $table->id();
            $table->string('action', 180);
            $table->string('role', 40);
            $table->enum('channel', ['app', 'mail']);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['action', 'role', 'channel'], 'uq_notification_rules_action_role_channel');
            $table->index(['action', 'channel', 'enabled'], 'idx_notification_rules_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_rules');
    }
};
