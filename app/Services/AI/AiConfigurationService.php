<?php

namespace App\Services\AI;

use App\Models\AiConfiguration;
use Illuminate\Support\Facades\Cache;

class AiConfigurationService
{
    const CACHE_KEY = 'ai_db_config';

    /**
     * Get all active provider configurations from DB only.
     * Returns empty array if no DB configs exist.
     */
    public function getActiveProviders(): array
    {
        return $this->fromDb();
    }

    /**
     * Get configuration for a single provider from DB only (sin api_key).
     */
    public function getProviderConfig(string $provider): ?array
    {
        $dbConfigs = $this->fromDb();
        return $dbConfigs[$provider] ?? null;
    }

    /**
     * Obtiene la API key de un proveedor directamente desde la BD.
     * Nunca se cachea — solo en memoria durante el request.
     */
    public function getApiKey(string $provider): ?string
    {
        $record = AiConfiguration::where('provider', $provider)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->first();

        return $record?->api_key;
    }

    /**
     * Get provider order (comma-separated) from DB or file.
     */
    public function getProviderOrder(): array
    {
        $dbConfigs = $this->fromDb();

        if (!empty($dbConfigs)) {
            // Maintain sort_order from DB
            $ordered = collect($dbConfigs)
                ->sortBy('sort_order')
                ->keys()
                ->toArray();

            if (!empty($ordered)) {
                return $ordered;
            }
        }

        // Default order if no DB configs
        return ['openai', 'gemini', 'anthropic'];
    }

    /**
     * Sync all active DB configurations to cache.
     * Called after any CRUD operation on ai_configurations.
     */
    public function syncToCache(): void
    {
        $configs = $this->loadFromDb();

        if (empty($configs)) {
            Cache::forget(self::CACHE_KEY);
            return;
        }

        Cache::forever(self::CACHE_KEY, $configs);
    }

    /**
     * Clear the cached configuration.
     */
    public function clearCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    // ── Private helpers ──

    private function fromDb(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            return $this->loadFromDb();
        });
    }

    private function loadFromDb(): array
    {
        $records = AiConfiguration::active()->ordered()->get();

        if ($records->isEmpty()) {
            return [];
        }

        $result = [];
        foreach ($records as $record) {
            $provider = $record->provider;
            // Only the first (lowest sort_order) active config per provider is used
            if (isset($result[$provider])) {
                // Mark as fallback
                if (!isset($result["{$provider}_fallback"])) {
                    $result["{$provider}_fallback"] = $this->toServiceConfig($record);
                }
                continue;
            }
            $result[$provider] = $this->toServiceConfig($record);

            // If this record has a fallback sibling, add it
            if ($record->is_fallback) {
                // Already handled
            }
        }

        return $result;
    }

    private function toServiceConfig(AiConfiguration $record): array
    {
        return [
            'enabled'    => $record->is_active,
            // api_key no se cachea — se obtiene vía getApiKey() directamente desde BD
            'model'      => $record->model,
            'max_tokens' => $record->max_tokens ?? 4096,
            'base_url'   => $record->base_url ?? $this->defaultBaseUrl($record->provider),
        ];
    }

    private function defaultBaseUrl(string $provider): string
    {
        return match ($provider) {
            'openai'    => 'https://api.openai.com/v1',
            'anthropic' => 'https://api.anthropic.com/v1',
            'gemini'    => 'https://generativelanguage.googleapis.com/v1',
            default     => '',
        };
    }
}
