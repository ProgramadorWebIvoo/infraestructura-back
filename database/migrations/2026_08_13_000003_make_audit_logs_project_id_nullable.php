<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * `audit_logs.project_id` era NOT NULL porque hasta ahora AuditLog solo
     * registraba eventos de negocio sobre un Project. El reset de contraseña
     * (User::sendPasswordResetNotification) es un evento auditable real sin
     * proyecto asociado — para integrarlo al mismo pipeline de auditoría y
     * control de notificaciones de CONFIG APP (en vez de un canal paralelo
     * sin auditoría ni configuración), la columna pasa a ser nullable.
     */
    public function up(): void
    {
        // SQLite (usado en tests) no soporta dropForeign() explícito — Doctrine
        // DBAL recrea la tabla completa al hacer ->change(), preservando la FK
        // existente automáticamente, así que alcanza con el ->change() solo.
        // En MySQL/Postgres (producción) ->change() también altera la columna
        // in-place sin tocar la FK, que sigue apuntando a la misma columna.
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('project_id', 40)->nullable()->change();
            $table->string('project_title_snapshot', 220)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Elimina cualquier registro sin proyecto antes de forzar NOT NULL de
        // vuelta — de lo contrario el ALTER fallaría con filas nulas.
        \Illuminate\Support\Facades\DB::table('audit_logs')->whereNull('project_id')->delete();

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('project_id', 40)->nullable(false)->change();
            $table->string('project_title_snapshot', 220)->nullable(false)->change();
        });
    }
};
