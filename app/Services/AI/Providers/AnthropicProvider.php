<?php

namespace App\Services\AI\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class AnthropicProvider extends OpenAIProvider implements AIProviderInterface
{
    /**
     * @param array $config { api_key, model, base_url, timeout, max_tokens }
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
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
            ->retry(2, 1000)
            ->withHeaders([
                'x-api-key'         => $this->apiKey,
                'anthropic-version' => '2023-06-01',
            ])
            ->post("{$this->baseUrl}/messages", [
                'model'       => $this->model,
                'max_tokens'  => $this->maxTokens,
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
