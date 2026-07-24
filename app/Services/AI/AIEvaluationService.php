<?php

namespace App\Services\AI;

use App\Models\AiUsageLog;
use App\Services\AI\Providers\AIProviderInterface;
use App\Services\AI\Providers\OpenAIProvider;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\AnthropicProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AIEvaluationService
{
    /** Mapa de proveedores disponibles */
    private array $providers = [];

    /** Bitácora de intentos (para devolver al frontend) */
    private array $attemptLog = [];

    private AiConfigurationService $configService;

    public function __construct(?AiConfigurationService $configService = null)
    {
        $this->configService = $configService ?? app(AiConfigurationService::class);
        $this->registerProviders();
    }

    /**
     * Registra los providers habilitados según configuración en BD.
     * Si no hay configuración en BD, no registra ningún provider (fail-fast).
     */
    private function registerProviders(): void
    {
        $map = [
            'openai'    => OpenAIProvider::class,
            'gemini'    => GeminiProvider::class,
            'anthropic' => AnthropicProvider::class,
        ];

        // Solo configuración desde BD
        $dbProviders = $this->configService->getActiveProviders();

        if (empty($dbProviders)) {
            // Sin configs en BD → no hay providers disponibles
            return;
        }

        $order = $this->configService->getProviderOrder();

        foreach ($order as $key) {
            $key = trim($key);
            if (!isset($map[$key])) {
                continue;
            }
            $class = $map[$key];
            $config = $dbProviders[$key] ?? $this->configService->getProviderConfig($key);

            if ($config && ($config['enabled'] ?? true) && !empty($config['api_key'])) {
                config(["ai.{$key}" => $config]);
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
        $startTime = microtime(true);

         foreach ($this->providers as $key => $provider) {
            try {
                $this->logAttempt("Intentando con {$provider->name()}...");

                $result = $provider->evaluate($payload);

                $this->logAttempt("✓ {$provider->name()} respondió exitosamente.");
                $result['attemptLog'] = $this->attemptLog;

                // Log usage to database
                $this->logUsage($payload, $key, $result, $startTime, true, null);

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
            : "No hay proveedores AI configurados en la base de datos. Configure al menos un proveedor en /config-ia";

        // Log failed attempt
        $this->logUsage($payload, null, [], $startTime, false, $errorMsg);

        throw new RuntimeException($errorMsg);
    }


/**
     * Metodo que permite aceptar proveedores de IA de manera Forzada/
     */
    public function evaluateWithProvider(array $payload, ?string $forcedprovider = null): array
    {
        $startTime = microtime(true);

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

                // Log usage to database
                $this->logUsage($payload, $forcedprovider, $result, $startTime, true, null);

                return $result;
            } catch (\Throwable $e) {
                $this->logAttempt("✗ {$provider->name()}: {$e->getMessage()}");

                Log::warning("AI Evaluation forced provider [{$provider->name()}] failed: {$e->getMessage()}", [
                    'projectId' => $payload['project']['projectId'] ?? null,
                    'exception_class' => get_class($e),
                ]);

                // Log failed usage
                $this->logUsage($payload, $forcedprovider, [], $startTime, false, $e->getMessage());

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

    /**
     * Registra el uso de IA en la base de datos.
     */
    private function logUsage(array $payload, string $provider, array $result, float $startTime, bool $success, ?string $errorMessage = null): void
    {
        try {
            $usage = $result['usage'] ?? [];
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Estimar costo basado en tokens (aproximado)
            $costEstimate = $this->estimateCost($provider, $usage);

            AiUsageLog::create([
                'provider'           => $provider,
                'model'              => $result['providerUsed'] ?? config("ai.{$provider}.model", 'unknown'),
                'endpoint'           => 'evaluate-proposals',
                'prompt_tokens'      => $usage['prompt_tokens'] ?? null,
                'completion_tokens'  => $usage['completion_tokens'] ?? null,
                'total_tokens'       => $usage['total_tokens'] ?? null,
                'cost_estimate'      => $costEstimate,
                'response_time_ms'   => $responseTimeMs,
                'success'            => $success,
                'error_message'      => $errorMessage,
                'requested_by'       => Auth::id(),
            ]);
        } catch (\Throwable $e) {
            // No romper el flujo principal si falla el logging
            Log::warning('Failed to log AI usage', [
                'error' => $e->getMessage(),
                'provider' => $provider,
            ]);
        }
    }

    /**
     * Estima el costo en USD basado en tokens y proveedor.
     * Precios aproximados (actualizar según pricing oficial).
     */
    private function estimateCost(string $provider, array $usage): float
    {
        $promptTokens = $usage['prompt_tokens'] ?? 0;
        $completionTokens = $usage['completion_tokens'] ?? 0;

        // Precios por 1M tokens (USD) - valores aproximados 2024
        $pricing = [
            'openai'    => ['input' => 2.50, 'output' => 10.00],   // gpt-4o
            'gemini'    => ['input' => 0.35, 'output' => 1.05],    // gemini-1.5-pro
            'anthropic' => ['input' => 3.00, 'output' => 15.00],   // claude-3-opus
        ];

        $rates = $pricing[$provider] ?? ['input' => 0, 'output' => 0];

        return round(
            ($promptTokens / 1_000_000) * $rates['input'] +
            ($completionTokens / 1_000_000) * $rates['output'],
            6
        );
    }

    /**
     * Obtiene el modelo configurado para un proveedor.
     */
    private function getModelForProvider(?string $provider): string
    {
        if (!$provider) return 'unknown';
        return config("ai.{$provider}.model", $provider);
    }
}
