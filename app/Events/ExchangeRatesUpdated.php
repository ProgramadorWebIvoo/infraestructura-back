<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

class ExchangeRatesUpdated implements ShouldBroadcastNow
{
    use Dispatchable;

    public function __construct(
        public array $rates,
        public string $source,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('exchange-rates'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'exchange-rates.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'rates' => $this->rates,
            'source' => $this->source,
            'updated_at' => now()->toIso8601String(),
        ];
    }
}
