<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicProvider extends OpenAIProvider implements AIProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = config('ai.anthropic.api_key');
        $this->model   = config('ai.anthropic.model', 'claude-3-opus-20240229');
        $this->baseUrl = config('ai.anthropic.base_url', 'https://api.anthropic.com/v1');
        $this->timeout = config('ai.timeout', 30);
    }

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
            ->withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->post("{$this->baseUrl}/messages", [
                'model'       => $this->model,
                'max_tokens'  => config('ai.anthropic.max_tokens', 4096),
                'temperature' => 0.3,
                'system'      => $systemPrompt,
                'messages'    => [
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

        if (!$content) {
            throw new RuntimeException('Anthropic devolvió una respuesta vacía.');
        }

        return $this->parseResponse($content);
    }

    protected function parseResponse(string $content): array
    {
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content));

        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new RuntimeException('No se pudo parsear la respuesta JSON de Anthropic.');
        }

        return $this->normalizeResult($data, 'claude');
    }
}
