<?php

namespace App\Services\AI\Providers;

use App\Services\AI\EvaluationStrategyInterface;

/**
 * Punto único de resolución provider-key → clase concreta. Antes
 * AIEvaluationService::registerProviders() mezclaba esta resolución con
 * lectura de config e instanciación directa (`new $class(...)`), violando
 * DIP al acoplar el servicio a las 3 clases concretas de provider.
 */
class AIProviderFactory
{
    private const MAP = [
        'openai'    => OpenAIProvider::class,
        'gemini'    => GeminiProvider::class,
        'anthropic' => AnthropicProvider::class,
    ];

    public static function supports(string $key): bool
    {
        return isset(self::MAP[$key]);
    }

    public static function make(string $key, array $config, ?EvaluationStrategyInterface $strategy = null): AIProviderInterface
    {
        if (!self::supports($key)) {
            throw new \InvalidArgumentException("Proveedor AI desconocido: {$key}");
        }

        $class = self::MAP[$key];

        return new $class($config, $strategy);
    }
}
