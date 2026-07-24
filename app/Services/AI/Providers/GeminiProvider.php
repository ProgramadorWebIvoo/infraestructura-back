<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class GeminiProvider extends BaseAIProvider
{
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
                    'maxOutputTokens' => $this->maxTokens,
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
        $usage = $body['usageMetadata'] ?? null;

        if (!$content) {
            throw new RuntimeException('Gemini devolvió una respuesta vacía.');
        }

        $result = $this->parseResponse($content);
        if ($usage) {
            $result['usage'] = [
                'prompt_tokens'     => $usage['promptTokenCount'] ?? 0,
                'completion_tokens' => $usage['candidatesTokenCount'] ?? 0,
                'total_tokens'      => $usage['totalTokenCount'] ?? 0,
            ];
        }

        return $result;
    }
}
