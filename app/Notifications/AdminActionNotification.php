<?php

namespace App\Notifications;

use App\Services\ExpoPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

/**
 * Equivalente de ProjectActionNotification (push Expo) para acciones
 * administrativas sin proyecto asociado — ver AdminActionMail y Hallazgo 2
 * de la auditoría Fase 0-1.
 */
class AdminActionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $action,
        public ?string $details = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['expo'];
    }

    public function toExpo(object $notifiable): void
    {
        app(ExpoPushService::class)->sendToUser(
            $notifiable->id,
            'IVOO Gestión',
            $this->action,
            ['screen' => 'admin'],
        );
    }
}
