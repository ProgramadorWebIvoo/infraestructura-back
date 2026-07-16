<?php

namespace App\Services\AI;

use App\Services\AI\Providers\AIProviderInterface;
use App\Services\AI\Providers\OpenAIProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\AnthropicProvider;
use RuntimeException;
use Illuminate\Support\Facades\Log;

class AIEvaluationService
{
    /** Mapa de proveedores disponibles */
    private array $providers = [];

    /** Bitácora de intentos (para devolver al frontend) */
    private array $attemptLog = [];

    public function __construct()
    {
        $this->registerProviders();
    }

    /**
     * Registra los providers habilitados según configuración.
     */
    private function registerProviders(): void
    {
        $map = [
            'openai'    => OpenAIProvider::class,
            'gemini'    => GeminiProvider::class,
            'anthropic' => AnthropicProvider::class,
        ];

        $order = explode(',', config('ai.provider_order', 'openai,gemini,anthropic'));

        foreach ($order as $key) {
            $key = trim($key);
            if (!isset($map[$key])) {
                continue;
            }
            $class = $map[$key];
            $enabled = config("ai.{$key}.enabled", true);

            // Solo registrar si está habilitado y tiene API key configurada
            if ($enabled && !empty(config("ai.{$key}.api_key"))) {
                $this->providers[$key] = new $class();
            }
        }
    }

    /**
     * Evalúa las propuestas intentando cada proveedor en orden.
     * Si uno falla por rate-limit o timeout, pasa al siguiente.
     *
     * @param array $payload Datos normalizados del proyecto y propuestas
     * @return array Resultado con winner, score, análisis, etc.
     * @throws RuntimeException Si todos los proveedores fallan
     */
    public function evaluate(array $payload): array
    {
        $this->attemptLog = [];
        $lastException = null;

        foreach ($this->providers as $key => $provider) {
            try {
                $this->logAttempt("Intentando con {$provider->name()}...");

                $result = $provider->evaluate($payload);

                $this->logAttempt("✓ {$provider->name()} respondió exitosamente.");
                $result['attemptLog'] = $this->attemptLog;

                return $result;

            } catch (RuntimeException $e) {
                $lastException = $e;
                $message = $e->getMessage();

                $this->logAttempt("✗ {$provider->name()}: {$message}");

                Log::warning("AI Evaluation failover [{$provider->name()}]: {$message}", [
                    'projectId' => $payload['project']['projectId'] ?? null,
                ]);

                // Si es rate limit (429), continuamos con el siguiente
                if (str_contains($message, 'Rate limit')) {
                    continue;
                }

                // Si es timeout, continuamos
                if (str_contains($message, 'timeout') || str_contains($message, 'cURL error 28')) {
                    continue;
                }

                // Para otros errores (4xx, 5xx), continuamos igual
                continue;
            }
        }

        // Si llegamos aquí, todos fallaron
        $errorMsg = $lastException
            ? "Todos los proveedores fallaron. Último error: {$lastException->getMessage()}"
            : "No hay proveedores AI configurados. Verifica las API keys en .env";

        throw new RuntimeException($errorMsg);
    }

    /**
     * Devuelve la bitácora de intentos (para diagnóstico).
     */
    public function getAttemptLog(): array
    {
        return $this->attemptLog;
    }

    private function logAttempt(string $message): void
    {
        $this->attemptLog[] = $message;
    }
}
