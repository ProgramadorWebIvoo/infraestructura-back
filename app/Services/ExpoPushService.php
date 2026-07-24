<?php

namespace App\Services;

use App\Models\PushToken;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

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
            $this->sendBatch($userId, $chunk);
        }
    }

    public function sendToToken(string $token, string $title, string $body, array $data = []): void
    {
        $response = Http::post(self::EXPO_API, [[
            'to' => $token,
            'sound' => 'default',
            'title' => $title,
            'body' => $body,
            'data' => $data,
            'priority' => 'high',
        ]]);

        $this->processResponse(null, [['to' => $token]], $response);
    }

    /**
     * Envía un batch (máx 100) y procesa la respuesta para limpiar tokens inválidos.
     */
    private function sendBatch(?int $userId, $messages): void
    {
        $response = Http::post(self::EXPO_API, $messages->values()->toArray());

        if ($response->failed()) {
            Log::warning('Expo API request failed', [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
            return;
        }

        $this->processResponse($userId, $messages, $response);
    }

    /**
     * Analiza la respuesta de Expo y elimina tokens con DeviceNotRegistered.
     */
    private function processResponse(?int $userId, $messages, $response): void
    {
        $body = $response->json();
        $items = $body['data'] ?? [];

        foreach ($items as $i => $item) {
            if (($item['status'] ?? '') === 'ok') {
                continue;
            }

            $error = $item['details']['error'] ?? '';
            if ($error !== 'DeviceNotRegistered' && $error !== 'ExponentNotRegistered') {
                continue;
            }

            $failedToken = $messages[$i]['to'] ?? null;
            if (!$failedToken) {
                continue;
            }

            $query = PushToken::where('token', $failedToken);
            if ($userId !== null) {
                $query->where('user_id', $userId);
            }
            $query->delete();

            Log::info('Push token eliminado por DeviceNotRegistered', [
                'user_id' => $userId,
                'token'   => $failedToken,
            ]);
        }
    }
}
