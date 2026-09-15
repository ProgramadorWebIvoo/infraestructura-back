<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para los nuevos filtros server-side de /audit-logs (Fase 3 del
 * plan de refuerzo de auditorías) — mismo patrón que
 * 2026_08_20_000000_add_indexes_to_config_audit_logs_table.php.
 * `user_id` no necesita índice propio: InnoDB ya lo indexa implícitamente
 * por la FK `fk_audit_logs_user` (2026_07_01_153523).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index('action', 'audit_logs_action_index');
            $table->index('user_name_snapshot', 'audit_logs_user_name_snapshot_index');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex('audit_logs_action_index');
            $table->dropIndex('audit_logs_user_name_snapshot_index');
        });
    }
};
