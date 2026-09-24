<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Avisa al proveedor que su oferta fue adjudicada. Va a la dirección del
 * contratista (Contractor::email), no a un usuario del sistema — se despacha
 * con `Notification::route('mail', $email)`.
 */
class SupplierAwardNotice extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        protected string $projectTitle,
        protected string $contractorName,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Adjudicación — {$this->projectTitle} — IVOO Gestión")
            ->greeting("Hola, {$this->contractorName}")
            ->line("IVOO Gestión de Infraestructura le informa que su oferta para el proyecto \"{$this->projectTitle}\" fue adjudicada.")
            ->line('El área de Finanzas procesará el anticipo correspondiente y se le notificará cuando sea liberado.');
    }
}
