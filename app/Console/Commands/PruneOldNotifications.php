<?php

namespace App\Console\Commands;

use App\Models\AppNotification;
use App\Services\SettingsService;
use Illuminate\Console\Command;

class PruneOldNotifications extends Command
{
    protected $signature = 'notifications:prune';
    protected $description = 'Elimina notificaciones de la bandeja interna más antiguas que la retención configurada en CONFIG APP';

    public function handle(): int
    {
        $days = (int) SettingsService::get('retencion_notificaciones_dias', 90);

        $deleted = AppNotification::where('created_at', '<', now()->subDays($days))->delete();

        $this->info("Eliminadas {$deleted} notificación(es) con más de {$days} día(s) de antigüedad.");

        return self::SUCCESS;
    }
}
