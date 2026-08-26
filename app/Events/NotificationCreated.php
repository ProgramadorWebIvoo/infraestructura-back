<?php

namespace App\Events;

use App\Models\AppNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Empuja una AppNotification recién creada al canal privado de su
 * destinatario (routes/channels.php: App.Models.User.{id}), reemplazando el
 * polling de NotificationsProvider por push instantáneo — ver
 * NotificationDispatcher::notify(), que dispara este evento por cada
 * destinatario dentro del mismo loop que crea la fila en BD.
 *
 * ShouldBroadcastNow (no ShouldBroadcast): el broadcast es síncrono dentro
 * del request HTTP, sin depender de un worker de cola corriendo — a
 * propósito, ver el try/catch alrededor de broadcast() en el dispatcher.
 */
class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(public AppNotification $notification)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->notification->user_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'notification.created';
    }

    /**
     * Mismo shape que ya expone GET /notifications (serialización Eloquent
     * directa, sin Resource — ver AppNotificationController::index()), para
     * que el frontend pueda insertar el payload recibido tal cual sin
     * transformación ni refetch.
     */
    public function broadcastWith(): array
    {
        return $this->notification->toArray();
    }
}
