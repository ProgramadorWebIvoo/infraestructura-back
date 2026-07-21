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

            } catch (\Throwable $e) {
                $lastException = $e;
                $message = $e->getMessage();

                $this->logAttempt("✗ {$provider->name()}: {$message}");

                Log::warning("AI Evaluation failover [{$provider->name()}]: {$message}", [
                    'projectId' => $payload['project']['projectId'] ?? null,
                    'exception_class' => get_class($e),
                ]);

                // ConnectionException, timeout, rate limit, error HTTP → todos failover
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
     * Metodo que permite aceptar proveedores de IA de manera Forzada/ 
    */
    public function evaluateWithProvider(array $payload, ?string $forcedprovider = null): array
    {
        if ($forcedprovider) {
            $provider = $this->providers[$forcedprovider] ?? null;
            if (!$provider) {
                throw new RuntimeException("Proveedor '$forcedprovider' no configurado");
            }

            $this->attemptLog = [];
            $this->logAttempt("Forzando evaluación con {$provider->name()}...");

            try {
                $result = $provider->evaluate($payload);
                $this->logAttempt("✓ {$provider->name()} respondió exitosamente.");
                $result['attemptLog'] = $this->attemptLog;

                return $result;
            } catch (\Throwable $e) {
                $this->logAttempt("✗ {$provider->name()}: {$e->getMessage()}");

                Log::warning("AI Evaluation forced provider [{$provider->name()}] failed: {$e->getMessage()}", [
                    'projectId' => $payload['project']['projectId'] ?? null,
                    'exception_class' => get_class($e),
                ]);

                throw new RuntimeException(
                    "El proveedor forzado {$provider->name()} falló: {$e->getMessage()}",
                    0,
                    $e
                );
            }
        }

        //FAILOVER (Vuelve a usar metodo regular principal)
        return $this->evaluate($payload);
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
