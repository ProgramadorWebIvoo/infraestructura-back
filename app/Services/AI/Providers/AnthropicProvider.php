<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicProvider extends BaseAIProvider
{
    /** Versión de la API de Anthropic (header `anthropic-version`). */
    public const API_VERSION = '2023-06-01';

    public function name(): string
    {
        return 'claude';
    }

    public function evaluate(array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('ANTHROPIC_API_KEY no configurada.');
        }

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt   = $this->buildUserPrompt($payload);

        $response = Http::timeout($this->timeout)
            ->retry(2, 1000)
            ->withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => self::API_VERSION,
            ])
            ->post("{$this->baseUrl}/messages", [
                'model'      => $this->model,
                'max_tokens' => $this->maxTokens,
                // Sin 'temperature': no soportada por los modelos Claude nuevos
                // (Opus 4.x / Sonnet 4.5+ / Haiku 4.5); enviarla devuelve 400.
                'system'     => $systemPrompt,
                'messages'   => [
                    ['role' => 'user', 'content' => $userPrompt],
                ],
            ]);

        if ($response->status() === 429) {
            throw new RuntimeException('Rate limit excedido en Anthropic.');
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "Anthropic error {$response->status()}: {$response->body()}"
            );
        }

        $body = $response->json();
        $content = $body['content'][0]['text'] ?? null;
        $usage = $body['usage'] ?? null;

        if (!$content) {
            throw new RuntimeException('Anthropic devolvió una respuesta vacía.');
        }

        $result = $this->parseResponse($content);
        if ($usage) {
            $result['usage'] = [
                'prompt_tokens'     => $usage['input_tokens'] ?? 0,
                'completion_tokens' => $usage['output_tokens'] ?? 0,
                'total_tokens'      => ($usage['input_tokens'] ?? 0) + ($usage['output_tokens'] ?? 0),
            ];
        }

        return $result;
    }

    public function healthCheck(): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'x-api-key'         => $this->apiKey,
                    'anthropic-version' => self::API_VERSION,
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $this->model,
                    'messages'   => [
                        ['role' => 'user', 'content' => 'Respond with "ok"'],
                    ],
                    'max_tokens' => 5,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Conexión exitosa con Anthropic.'];
            }

            $body = $response->json();
            $error = $body['error']['message'] ?? $response->body();

            return ['success' => false, 'message' => "Anthropic: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => "Anthropic: {$e->getMessage()}"];
        }
    }
}
