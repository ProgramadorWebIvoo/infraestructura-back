<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Auditoría de cambios en CONFIG APP — deliberadamente separada de
     * `audit_logs` (que registra acciones sobre proyectos y es visible para
     * cualquier autenticado, incluida Presidencia vía /audit-logs). Los
     * cambios de configuración de la aplicación son un dato administrativo,
     * visible solo para SUPERADMIN.
     */
    public function up(): void
    {
        Schema::create('config_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('setting_id')->nullable()->constrained('app_settings')->nullOnDelete();
            $table->string('setting_key', 80);
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('user_name_snapshot', 150)->nullable();
            $table->timestamp('changed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_audit_logs');
    }
};
