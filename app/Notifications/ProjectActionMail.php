<?php

namespace App\Notifications;

use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ProjectActionMail extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Project $project,
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
            ->subject("{$this->project->title} — {$this->action}")
            ->greeting('Hola, ' . $notifiable->name)
            ->line("Se registró una acción sobre el proyecto \"{$this->project->title}\": {$this->action}.");

        if ($this->details) {
            $mail->line($this->details);
        }

        return $mail->action('Ver proyecto', "{$frontendUrl}/proyectos/{$this->project->id}");
    }
}
