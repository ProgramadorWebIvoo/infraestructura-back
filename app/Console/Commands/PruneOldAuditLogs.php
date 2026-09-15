<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\ConfigAuditLog;
use App\Services\SettingsService;
use Illuminate\Console\Command;

/**
 * Purga AuditLog/ConfigAuditLog más antiguos que la retención configurada
 * en CONFIG APP — sin esto, ambas tablas crecen sin límite (Fase 3 del plan
 * de refuerzo de auditorías).
 *
 * Ambos modelos son inmutables (IsImmutableAuditRecord bloquea `deleting`) —
 * este comando es la ÚNICA excepción intencional, vía `withoutEvents()`. No
 * replicar ese patrón en ningún otro lugar del código.
 */
class PruneOldAuditLogs extends Command
{
    protected $signature = 'audit:prune';
    protected $description = 'Elimina registros de auditoría (AuditLog y ConfigAuditLog) más antiguos que la retención configurada en CONFIG APP';

    public function handle(): int
    {
        $months = (int) SettingsService::get('retencion_auditoria_meses', 24);
        $cutoff = now()->subMonths($months);

        $auditLogsDeleted = AuditLog::withoutEvents(
            fn () => AuditLog::where('logged_at', '<', $cutoff)->delete()
        );

        $configAuditLogsDeleted = ConfigAuditLog::withoutEvents(
            fn () => ConfigAuditLog::where('changed_at', '<', $cutoff)->delete()
        );

        $this->info("Eliminados {$auditLogsDeleted} registro(s) de AuditLog y {$configAuditLogsDeleted} de ConfigAuditLog con más de {$months} mes(es) de antigüedad.");

        return self::SUCCESS;
    }
}
