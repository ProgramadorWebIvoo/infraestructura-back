<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 de la auditoría de Presidencia: conecta el rol PRESIDENCIA a las
 * dos alertas proactivas de riesgo ejecutivo ('Obra sin actividad reciente'
 * y 'Sobre-ejecucion de presupuesto', ambas disparadas por el comando
 * `alertas:vencimientos`).
 *
 * Antes de esta migración ninguna de las dos tenía fila en
 * notification_rules, así que caían al fallback de
 * NotificationRuleResolver::rolesFor() (solo SUPERADMIN/ADMIN) — Presidencia,
 * que es el rol que consume estas señales en su propio dashboard (ver
 * StalledProjectsSection y el KPI de monto comprometido), nunca se enteraba
 * de forma proactiva, solo si entraba a revisar el panel.
 *
 * Se seedean también SUPERADMIN/ADMIN de forma explícita: en cuanto una
 * acción tiene AL MENOS una fila configurada, el resolver deja de aplicar el
 * fallback administrativo — omitirlos aquí habría sido, en la práctica,
 * quitarles una notificación que ya recibían.
 *
 * Idempotente vía upsert sobre el UNIQUE (action, role, channel).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $rows = [];

        $addRule = function (string $action, array $roles, string $channel) use (&$rows, $now) {
            foreach ($roles as $role) {
                $rows[] = [
                    'action' => $action,
                    'role' => $role,
                    'channel' => $channel,
                    'enabled' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        };

        $addRule('Obra sin actividad reciente', ['PRESIDENCIA', 'SUPERADMIN', 'ADMIN'], 'app');
        $addRule('Sobre-ejecucion de presupuesto', ['PRESIDENCIA', 'SUPERADMIN', 'ADMIN'], 'app');

        DB::table('notification_rules')->upsert(
            $rows,
            ['action', 'role', 'channel'],
            ['enabled', 'updated_at'],
        );
    }

    public function down(): void
    {
        DB::table('notification_rules')
            ->whereIn('action', ['Obra sin actividad reciente', 'Sobre-ejecucion de presupuesto'])
            ->whereIn('role', ['PRESIDENCIA', 'SUPERADMIN', 'ADMIN'])
            ->where('channel', 'app')
            ->delete();
    }
};
