<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAIProvider extends BaseAIProvider
{
    public function name(): string
    {
        return 'openai';
    }

    public function evaluate(array $payload): array
    {
        if (empty($this->apiKey)) {
            throw new RuntimeException('OPENAI_API_KEY no configurada.');
        }

        $systemPrompt = $this->buildSystemPrompt();
        $userPrompt   = $this->buildUserPrompt($payload);

        $response = Http::timeout($this->timeout)
            ->retry(2, 1000)
            ->withToken($this->apiKey)
            ->post("{$this->baseUrl}/chat/completions", [
                'model' => $this->model,
                'messages' => [
                    ['role' => 'system', 'content' => $systemPrompt],
                    ['role' => 'user',   'content' => $userPrompt],
                ],
                'temperature' => 0.3,
                'max_tokens'  => $this->maxTokens,
            ]);

        if ($response->status() === 429) {
            throw new RuntimeException('Rate limit excedido en OpenAI.');
        }

        if ($response->failed()) {
            throw new RuntimeException(
                "OpenAI error {$response->status()}: {$response->body()}"
            );
        }

        $body = $response->json();
        $content = $body['choices'][0]['message']['content'] ?? null;
        $usage = $body['usage'] ?? null;

        if (!$content) {
            throw new RuntimeException('OpenAI devolvió una respuesta vacía.');
        }

        $result = $this->parseResponse($content);
        if ($usage) {
            $result['usage'] = [
                'prompt_tokens'     => $usage['prompt_tokens'] ?? 0,
                'completion_tokens' => $usage['completion_tokens'] ?? 0,
                'total_tokens'      => $usage['total_tokens'] ?? 0,
            ];
        }

        return $result;
    }
}
