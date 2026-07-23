<?php

namespace App\Services;

use App\Models\PushToken;
use Illuminate\Support\Facades\Http;

class ExpoPushService
{
    private const EXPO_API = 'https://exp.host/--/api/v2/push/send';

    public function sendToUser(int $userId, string $title, string $body, array $data = []): void
    {
        $tokens = PushToken::where('user_id', $userId)->pluck('token');

        if ($tokens->isEmpty()) {
            return;
        }

        $messages = $tokens->map(fn (string $token) => [
            'to' => $token,
            'sound' => 'default',
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'priority' => 'high',
        ]);

        foreach ($messages->chunk(100) as $chunk) {
            Http::post(self::EXPO_API, $chunk->values()->toArray());
        }
    }

    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        Http::post(self::EXPO_API, [[
            'to' => $token,
            'sound' => 'default',
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'priority' => 'high',
        ]]);
    }
}
