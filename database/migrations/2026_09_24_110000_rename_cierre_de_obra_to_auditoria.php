<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Renombra "Cierre de Obra" a "Auditoría" en todos los datos persistidos:
 * rol (CIERRE_DE_OBRA -> AUDITORIA), vista (/cierre-obra -> /auditoria),
 * departamento de IA, acción de notificación y columna de notas del proyecto.
 * Idempotente (solo toca filas con el valor viejo) y reversible.
 */
return new class extends Migration
{
    private const OLD_ROLE = 'CIERRE_DE_OBRA';
    private const NEW_ROLE = 'AUDITORIA';
    private const OLD_VIEW = '/cierre-obra';
    private const NEW_VIEW = '/auditoria';
    private const OLD_ACTION = 'Solicitud de reevaluación a Cierre de Obra';
    private const NEW_ACTION = 'Solicitud de reevaluación a Auditoría';
    private const OLD_LABEL = 'Cierre de Obra';
    private const NEW_LABEL = 'Auditoría';
    private const OLD_AI = 'ia.cierre_obra.evaluacion_expediente';
    private const NEW_AI = 'ia.auditoria.evaluacion_expediente';

    public function up(): void
    {
        $this->swap(self::OLD_ROLE, self::NEW_ROLE, self::OLD_VIEW, self::NEW_VIEW, self::OLD_ACTION, self::NEW_ACTION, self::OLD_LABEL, self::NEW_LABEL, self::OLD_AI, self::NEW_AI);

        if (Schema::hasColumn('projects', 'cierre_obra_notes')) {
            Schema::table('projects', fn (Blueprint $t) => $t->renameColumn('cierre_obra_notes', 'audit_notes'));
        }
    }

    public function down(): void
    {
        $this->swap(self::NEW_ROLE, self::OLD_ROLE, self::NEW_VIEW, self::OLD_VIEW, self::NEW_ACTION, self::OLD_ACTION, self::NEW_LABEL, self::OLD_LABEL, self::NEW_AI, self::OLD_AI);

        if (Schema::hasColumn('projects', 'audit_notes')) {
            Schema::table('projects', fn (Blueprint $t) => $t->renameColumn('audit_notes', 'cierre_obra_notes'));
        }
    }

    private function swap(string $fromRole, string $toRole, string $fromView, string $toView, string $fromAction, string $toAction, string $fromLabel, string $toLabel, string $fromAi, string $toAi): void
    {
        $now = now();

        foreach ([['users', 'role'], ['audit_logs', 'role'], ['role_view_access', 'role']] as [$table, $col]) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where($col, $fromRole)->update([$col => $toRole]);
            }
        }

        if (Schema::hasTable('roles')) {
            if (DB::table('roles')->where('key', $toRole)->exists()) {
                DB::table('roles')->where('key', $fromRole)->delete();
            } else {
                DB::table('roles')->where('key', $fromRole)->update(['key' => $toRole, 'label' => $toLabel, 'updated_at' => $now]);
            }
        }

        if (Schema::hasTable('ai_feature_toggles')) {
            DB::table('ai_feature_toggles')->where('department', $fromRole)->update(['department' => $toRole]);
            DB::table('ai_feature_toggles')->where('action', $fromAi)->update(['action' => $toAi]);
        }

        if (Schema::hasTable('view_definitions')) {
            DB::table('view_definitions')->where('key', $fromView)->update(['key' => $toView, 'label' => $toLabel, 'updated_at' => $now]);
        }
        if (Schema::hasTable('tab_definitions')) {
            DB::table('tab_definitions')->where('view_key', $fromView)->update(['view_key' => $toView]);
        }

        foreach ([['notification_rules', 'action'], ['audit_logs', 'action'], ['app_notifications', 'action'], ['notification_actions', 'key']] as [$table, $col]) {
            if (Schema::hasTable($table)) {
                DB::table($table)->where($col, $fromAction)->update([$col => $toAction]);
            }
        }

        if (Schema::hasTable('notification_rules')) {
            DB::table('notification_rules')->where('role', $fromRole)->update(['role' => $toRole]);
        }

        if (Schema::hasTable('app_settings')) {
            foreach (['acciones_con_notificacion_app', 'acciones_con_correo'] as $settingKey) {
                $setting = DB::table('app_settings')->where('key', $settingKey)->first();
                if ($setting === null || $setting->value === null) {
                    continue;
                }
                $actions = json_decode($setting->value, true);
                if (is_array($actions)) {
                    $actions = array_values(array_unique(array_map(fn ($a) => $a === $fromAction ? $toAction : $a, $actions)));
                    DB::table('app_settings')->where('key', $settingKey)->update(['value' => json_encode($actions, JSON_UNESCAPED_UNICODE), 'updated_at' => $now]);
                }
            }
        }
    }
};
