<?php

namespace App\Notifications;

use App\Models\Project;
use App\Services\ExpoPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ProjectStatusChanged extends Notification
{
    use Queueable;

    public function __construct(
        public Project $project,
        public string $oldStatus,
        public string $newStatus,
    ) {}

    public function via(object $notifiable): array
    {
        return ['expo'];
    }

    public function toExpo(object $notifiable): void
    {
        $statusLabels = [
            'CREADO' => 'Creado',
            'REVISADO_CIERRE' => 'Revisado (Cierre)',
            'CONFIRMADO_PROCURA' => 'Confirmado (Procura)',
            'COMPARATIVA_ENVIADA' => 'Comparativa enviada',
            'CONTRATADO' => 'Contratado',
            'EN_EJECUCION' => 'En ejecución',
            'VERIFICANDO_FINALIZACION' => 'Verificando finalización',
            'LISTO_PAGO_FINAL' => 'Listo para pago final',
            'COMPLETADO_PAGADO' => 'Completado',
        ];

        $label = $statusLabels[$this->newStatus] ?? $this->newStatus;
        $title = "{$this->project->title} — {$label}";
        $body = "Estado actualizado: {$label}";

        app(ExpoPushService::class)->sendToUser(
            $notifiable->id,
            $title,
            $body,
            [
                'screen' => 'presidencia',
                'projectId' => (string) $this->project->id,
            ],
        );
    }
}
