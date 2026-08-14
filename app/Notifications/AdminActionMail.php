<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Equivalente de ProjectActionMail para acciones administrativas sin
 * proyecto asociado (usuarios, proveedores, materiales, config IA,
 * settings, monedas, matriz de notificaciones). Antes NotificationDispatcher
 * nunca enviaba mail para estas acciones porque ProjectActionMail exige un
 * Project no-nulo — ver Hallazgo 2 de la auditoría Fase 0-1.
 */
class AdminActionMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $action,
        public ?string $details = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');

        $mail = (new MailMessage)
            ->subject("IVOO Gestión — {$this->action}")
            ->greeting('Hola, ' . $notifiable->name)
            ->line("Se registró una acción administrativa: {$this->action}.");

        if ($this->details) {
            $mail->line($this->details);
        }

        return $mail->action('Ir a la aplicación', $frontendUrl);
    }
}
