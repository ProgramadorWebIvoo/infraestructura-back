<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fase 3 de la auditoría de Presidencia: registra la acción disparada por
 * AlertStaleProjectsAndExpiringInvitations::alertOverExecutedProjects()
 * (comando `alertas:vencimientos`) en el catálogo — sin esta fila,
 * NotificationCatalog::exists() la desconoce y NotificationDispatcher::notify()
 * la trataría con el tipo por defecto en vez de 'advertencia'
 * (ver NotificationCatalog::TYPE_OVERRIDES).
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        DB::table('notification_actions')->updateOrInsert(
            ['key' => 'Sobre-ejecucion de presupuesto'],
            [
                'label' => null,
                'group' => 'proyectos',
                'scope' => 'project',
                'critical' => true,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }

    public function down(): void
    {
        DB::table('notification_actions')->where('key', 'Sobre-ejecucion de presupuesto')->delete();
    }
};
