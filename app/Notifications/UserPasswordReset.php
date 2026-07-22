<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class UserPasswordReset extends Notification
{
    use Queueable;

    public function __construct(
        protected string $token,
        protected string $email,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $resetUrl = "{$frontendUrl}/reset-password/{$this->token}?email={$this->email}";

        return (new MailMessage)
            ->subject('Restablecimiento de contraseña — IVOO Gestión')
            ->greeting('Hola, ' . $notifiable->name)
            ->line('Recibiste este correo porque se solicitó un restablecimiento de contraseña para tu cuenta de IVOO Gestión.')
            ->action('Restablecer contraseña', $resetUrl)
            ->line('Este enlace expirará en 60 minutos.')
            ->line('Si no solicitaste este cambio, puedes ignorar este mensaje.');
    }
}
