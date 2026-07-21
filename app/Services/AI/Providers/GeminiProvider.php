<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiProvider extends OpenAIProvider implements AIProviderInterface
{
    private string $apiKey;
    private string $model;
    private string $baseUrl;
    private int $timeout;

    public function __construct()
    {
        $this->apiKey  = config('ai.gemini.api_key');
        $this->model   = config('ai.gemini.model', 'gemini-1.5-pro');
        $this->baseUrl = config('ai.gemini.base_url', 'https://generativelanguage.googleapis.com/v1');
        $this->timeout = config('ai.timeout', 100);
    }

    public function name(): string
    {
        return 'gemini';
    }

    public function evaluate(array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('GEMINI_API_KEY no configurada.');
        }

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt   = $this->buildUserPrompt($payload);

        $response = Http::timeout($this->timeout)
            ->retry(2, 1000)
            ->post("{$this->baseUrl}/models/{$this->model}:generateContent?key={$this->apiKey}", [
                'contents' => [
                    [
                        'role' => 'user',
                        'parts' => [
                            ['text' => "{$systemPrompt}\n\n{$userPrompt}"],
                        ],
                    ],
                ],
                'generationConfig' => [
                    'temperature' => 0.3,
                    'maxOutputTokens' => config('ai.gemini.max_tokens', 8192),
                ],
            ]);

        if ($response->status() === 429) {
            throw new RuntimeException('Rate limit excedido en Gemini.');
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "Gemini error {$response->status()}: {$response->body()}"
            );
        }

        $body = $response->json();
        $content = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

        if (!$content) {
            throw new RuntimeException('Gemini devolvió una respuesta vacía.');
        }
        
        return $this->parseResponse($content);
    }

    protected function parseResponse(string $content): array
    {
        // Gemini a veces envuelve en ```json, otras veces no
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content));

        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new RuntimeException('No se pudo parsear la respuesta JSON de Gemini.');
        }

        return $this->normalizeResult($data, 'gemini');
    }
}
