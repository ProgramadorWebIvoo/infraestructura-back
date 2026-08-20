<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para los filtros server-side de /config-audit-logs (búsqueda,
 * entity_type, action, rango de fechas) — sin esto, cada filtro hace table
 * scan completo sobre una tabla que crece indefinidamente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('config_audit_logs', function (Blueprint $table) {
            $table->index(['entity_type', 'changed_at'], 'config_audit_logs_entity_type_changed_at_index');
            $table->index('action', 'config_audit_logs_action_index');
            $table->index('changed_at', 'config_audit_logs_changed_at_index');
            $table->index('user_name_snapshot', 'config_audit_logs_user_name_snapshot_index');
        });
    }

    public function down(): void
    {
        Schema::table('config_audit_logs', function (Blueprint $table) {
            $table->dropIndex('config_audit_logs_entity_type_changed_at_index');
            $table->dropIndex('config_audit_logs_action_index');
            $table->dropIndex('config_audit_logs_changed_at_index');
            $table->dropIndex('config_audit_logs_user_name_snapshot_index');
        });
    }
};
