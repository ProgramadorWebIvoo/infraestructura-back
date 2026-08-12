<?php

namespace App\Notifications;

use App\Models\Project;
use App\Services\ExpoPushService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Notification;

class ProjectActionNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public Project $project,
        public string $action,
        public string $status,
    ) {}

    public function via(object $notifiable): array
    {
        return ['expo'];
    }

    public function toExpo(object $notifiable): void
    {
        $title = "{$this->project->title} — {$this->action}";
        $body = $this->action;

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
