<?php

namespace App\Notifications\Channels;

use App\Services\ExpoPushService;
use Illuminate\Notifications\Notification;

class ExpoChannel
{
    public function __construct(
        private ExpoPushService $expoPush,
    ) {}

    public function send(object $notifiable, Notification $notification): void
    {
        if (method_exists($notification, 'toExpo')) {
            $notification->toExpo($notifiable);
        }
    }
}
