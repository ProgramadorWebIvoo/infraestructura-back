<?php

namespace App\Services\AI;

use App\Models\AiUsageLog;
use App\Services\AI\Providers\AIProviderFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class AIEvaluationService
{
    /** Timeout por llamada a proveedor IA (segundos). Config global, no por BD. */
    private const DEFAULT_TIMEOUT = 60;

    /** Config resuelta por provider (sin instanciar) — la instanciación se
     * defiere hasta conocer la EvaluationStrategyInterface a usar, ya que un
     * mismo AIEvaluationService ahora sirve más de un tipo de evaluación
     * (propuestas, expediente) y cada una necesita su propio prompt/esquema
     * inyectado en los providers. */
    private array $providerConfigs = [];

    /** Bitácora de intentos (para devolver al frontend) */
    private array $attemptLog = [];

    private AiConfigurationService $configService;

    public function __construct(AiConfigurationService $configService)
    {
        $this->configService = $configService;
        $this->providerConfigs = $this->resolveProviderConfigs();
    }

    /**
     * Resuelve la configuración de los providers habilitados según BD, sin
     * instanciarlos. Si no hay configuración en BD, devuelve vacío (fail-fast
     * en evaluate()/evaluateWithProvider()).
     */
    private function resolveProviderConfigs(): array
    {
        $dbProviders = $this->configService->getActiveProviders();

        if (empty($dbProviders)) {
            return [];
        }

        $order = $this->configService->getProviderOrder();
        $timeout = self::DEFAULT_TIMEOUT;
        $configs = [];

        foreach ($order as $key) {
            $key = trim($key);
            if (!AIProviderFactory::supports($key)) {
                continue;
            }
            $config = $dbProviders[$key] ?? $this->configService->getProviderConfig($key);

            if (!$config || !($config['enabled'] ?? true)) {
                continue;
            }

            // API key se obtiene directamente de BD, nunca del cache
            $apiKey = $this->configService->getApiKey($key);
            if (empty($apiKey)) {
                continue;
            }

            $config['api_key'] = $apiKey;
            $config['timeout'] = $timeout;

            $configs[$key] = $config;
        }

        return $configs;
    }

    /** Instancia los providers con la estrategia (prompt/esquema) del tipo de evaluación pedido. */
    private function buildProviders(EvaluationStrategyInterface $strategy): array
    {
        $providers = [];
        foreach ($this->providerConfigs as $key => $config) {
            $providers[$key] = AIProviderFactory::make($key, $config, $strategy);
        }
        return $providers;
    }

    /**
     * Evalúa las propuestas intentando cada proveedor en orden.
     * Si uno falla por rate-limit o timeout, pasa al siguiente.
     *
     * @param array $payload Datos normalizados del proyecto y propuestas
     * @return array Resultado con winner, score, análisis, etc.
     * @throws RuntimeException Si todos los proveedores fallan
     */
    public function evaluate(array $payload, ?EvaluationStrategyInterface $strategy = null): array
    {
        $strategy = $strategy ?? new ProposalEvaluationStrategy();
        $providers = $this->buildProviders($strategy);

        $this->attemptLog = [];
        $lastException = null;
        $startTime = microtime(true);

        foreach ($providers as $key => $provider) {
            try {
                $this->logAttempt("Intentando con {$provider->name()}...");

                $result = $provider->evaluate($payload);

                $this->logAttempt("✓ {$provider->name()} respondió exitosamente.");
                $result['attemptLog'] = $this->attemptLog;

                // Log usage to database
                $this->logUsage($payload, $key, $result, $startTime, true, null, $strategy->endpointKey());

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
        $this->logUsage($payload, null, [], $startTime, false, $errorMsg, $strategy->endpointKey());

        throw new RuntimeException($errorMsg);
    }


/**
     * Permite forzar la evaluación con un proveedor de IA específico en vez
     * de usar el failover automático por orden de prioridad.
     */
    public function evaluateWithProvider(array $payload, ?string $forcedProvider = null, ?EvaluationStrategyInterface $strategy = null): array
    {
        $strategy = $strategy ?? new ProposalEvaluationStrategy();
        $startTime = microtime(true);

        if ($forcedProvider) {
            $providers = $this->buildProviders($strategy);
            $provider = $providers[$forcedProvider] ?? null;
            if (!$provider) {
                throw new RuntimeException("Proveedor '$forcedProvider' no configurado");
            }

            $this->attemptLog = [];
            $this->logAttempt("Forzando evaluación con {$provider->name()}...");

            try {
                $result = $provider->evaluate($payload);
                $this->logAttempt("✓ {$provider->name()} respondió exitosamente.");
                $result['attemptLog'] = $this->attemptLog;

                // Log usage to database
                $this->logUsage($payload, $forcedProvider, $result, $startTime, true, null, $strategy->endpointKey());

                return $result;
            } catch (\Throwable $e) {
                $this->logAttempt("✗ {$provider->name()}: {$e->getMessage()}");

                Log::warning("AI Evaluation forced provider [{$provider->name()}] failed: {$e->getMessage()}", [
                    'projectId' => $payload['project']['projectId'] ?? null,
                    'exception_class' => get_class($e),
                ]);

                // Log failed usage
                $this->logUsage($payload, $forcedProvider, [], $startTime, false, $e->getMessage(), $strategy->endpointKey());

                throw new RuntimeException(
                    "El proveedor forzado {$provider->name()} falló: {$e->getMessage()}",
                    0,
                    $e
                );
            }
        }

        //FAILOVER (Vuelve a usar metodo regular principal)
        return $this->evaluate($payload, $strategy);
    }

    /** Evalúa el expediente completo (Cierre de Obra) con la estrategia de dossier. */
    public function evaluateDossier(array $payload, ?string $forcedProvider = null): array
    {
        return $this->evaluateWithProvider($payload, $forcedProvider, new DossierEvaluationStrategy());
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
    private function logUsage(array $payload, ?string $provider, array $result, float $startTime, bool $success, ?string $errorMessage = null, string $endpoint = 'evaluate-proposals'): void
    {
        try {
            $usage = $result['usage'] ?? [];
            $responseTimeMs = (int) ((microtime(true) - $startTime) * 1000);

            // Estimar costo basado en tokens (aproximado)
            $costEstimate = $this->estimateCost($provider ?? '', $usage);

            AiUsageLog::create([
                'project_id'         => $payload['project']['projectId'] ?? null,
                'provider'           => $provider,
                'model'              => $result['providerUsed'] ?? 'unknown',
                'endpoint'           => $endpoint,
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
}
