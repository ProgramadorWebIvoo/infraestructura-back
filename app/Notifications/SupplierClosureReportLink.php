<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Enlace público para que el contratista envíe su informe de cierre; se
 * reenvía con el motivo cuando el residente o Auditoría lo rechazan. Se envía
 * en línea (sin cola) para que un fallo de SMTP se detecte en la misma petición
 * y `mailSent` refleje la realidad aunque no haya worker corriendo.
 */
class SupplierClosureReportLink extends Notification
{
    public function __construct(
        protected string $projectTitle,
        protected string $token,
        protected ?string $rejectionReason = null,
    ) {}

    public function via($notifiable): array
    {
        return ['mail'];
    }

    public function toMail($notifiable): MailMessage
    {
        $frontendUrl = config('app.frontend_url', 'http://localhost:3000');
        $publicUrl = "{$frontendUrl}/cierre-publico/{$this->token}";

        $mail = (new MailMessage)->subject(
            ($this->rejectionReason ? 'Corrección del informe de cierre' : 'Informe de cierre de obra') . " — {$this->projectTitle} — IVOO Gestión"
        );

        if ($this->rejectionReason) {
            $mail->line("Su informe de cierre del proyecto \"{$this->projectTitle}\" fue devuelto con el siguiente motivo:")
                ->line($this->rejectionReason)
                ->line('Corrija la información y vuelva a enviarlo con el mismo enlace.');
        } else {
            $mail->line("Al finalizar la ejecución del proyecto \"{$this->projectTitle}\", envíe su informe de cierre (partidas ejecutadas y fotos de evidencia) desde el siguiente enlace personal.");
        }

        return $mail->action('Abrir informe de cierre', $publicUrl)
            ->line('Si no reconoce esta solicitud, puede ignorar este mensaje.');
    }
}
