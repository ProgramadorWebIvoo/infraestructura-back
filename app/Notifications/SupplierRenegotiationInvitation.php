<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Invita al proveedor a renegociar su oferta a través del enlace público
 * dedicado — se envía a la dirección de correo registrada del contratista
 * (Contractor::email), no a un usuario del sistema, por eso se despacha via
 * `Notification::route('mail', $email)->notify(...)` en vez de `$user->notify()`.
 */
class SupplierRenegotiationInvitation extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $projectTitle,
        protected string $contractorName,
        protected string $token,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $publicUrl = "{$frontendUrl}/renegociacion-publica/{$this->token}";

        return (new MailMessage)
            ->subject("Renegociación de oferta — {$this->projectTitle} — IVOO Gestión")
            ->greeting("Hola, {$this->contractorName}")
            ->line("IVOO Gestión de Infraestructura lo invita a renegociar su oferta para el proyecto \"{$this->projectTitle}\".")
            ->line('Use el siguiente enlace para ingresar sus nuevas condiciones. El enlace es de un solo uso y personal.')
            ->action('Renegociar oferta', $publicUrl)
            ->line('Si no reconoce esta solicitud, puede ignorar este mensaje.');
    }
}
