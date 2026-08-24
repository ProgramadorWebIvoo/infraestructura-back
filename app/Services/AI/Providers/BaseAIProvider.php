<?php

namespace App\Services\AI\Providers;

use App\Services\AI\EvaluationStrategyInterface;
use App\Services\AI\ProposalEvaluationStrategy;
use RuntimeException;

abstract class BaseAIProvider implements AIProviderInterface
{
    protected string $apiKey;
    protected string $model;
    protected string $baseUrl;
    protected int $timeout;
    protected int $maxTokens;
    protected EvaluationStrategyInterface $strategy;

    /**
     * @param array $config { api_key, model, base_url, timeout, max_tokens }
     */
    public function __construct(array $config = [], ?EvaluationStrategyInterface $strategy = null)
    {
        $this->apiKey    = $config['api_key'] ?? '';
        $this->model     = $config['model'] ?? '';
        $this->baseUrl   = $config['base_url'] ?? '';
        $this->timeout   = $config['timeout'] ?? 30;
        $this->maxTokens = $config['max_tokens'] ?? 0;
        // Default preserva compatibilidad con callers que no pasan strategy
        // (evaluación de propuestas, el caso original antes de generalizar).
        $this->strategy  = $strategy ?? new ProposalEvaluationStrategy();
    }

    /**
     * Clave interna del proveedor (ej: 'openai', 'gemini', 'anthropic').
     * Se usa en logs y en el campo providerUsed del resultado.
     */
    abstract public function name(): string;

    abstract public function evaluate(array $payload): array;

    protected function buildSystemPrompt(): string
    {
        return $this->strategy->buildSystemPrompt();
    }

    protected function buildUserPrompt(array $payload): string
    {
        return $this->strategy->buildUserPrompt($payload, fn (string $v) => $this->sanitizeInput($v));
    }

    /**
     * Sanitiza texto ingresado por el usuario para prevenir prompt injection.
     * - Elimina caracteres de control (excepto tabs/saltos de línea simples)
     * - Neutraliza patrones comunes de jailbreak
     * - Limita longitud
     */
    protected function sanitizeInput(string $value): string
    {
        // 1. Eliminar caracteres de control excepto \t \n \r
        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);

        // 2. Neutralizar patrones comunes de intento de injection
        $patterns = [
            '/ignore\s+(all\s+)?(previous|above|below)\s+instructions/i',
            '/forget\s+(all\s+)?(previous|above|below)\s+(instructions|prompts?)/i',
            '/disregard\s+(all\s+)?(previous|above|below)/i',
            '/you\s+are\s+(now|not\s+required\s+to)/i',
            '/act\s+as\s+(if|though)/i',
            '/new\s+prompt/i',
            '/system\s+(prompt|instruction|message)/i',
            '/jailbreak/i',
            '/do\s+(not\s+)?(follow|obey|adhere)/i',
        ];

        $value = preg_replace($patterns, '[INYECCION_BLOQUEADA]', $value);

        // 3. Limitar longitud (máximo 2000 caracteres)
        if (mb_strlen($value) > 2000) {
            $value = mb_substr($value, 0, 2000) . '... [TRUNCADO]';
        }

        return $value;
    }

    protected function parseResponse(string $content): array
    {
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', trim($content));

        $data = json_decode($content, true);

        if (!is_array($data)) {
            throw new RuntimeException("No se pudo parsear la respuesta JSON de {$this->name()}.");
        }

        return $this->normalizeResult($data, $this->name());
    }

    protected function normalizeResult(array $data, string $provider): array
    {
        return $this->strategy->normalizeResult($data, $provider);
    }
}
