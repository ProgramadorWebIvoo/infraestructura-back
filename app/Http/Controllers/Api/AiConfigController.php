<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConfiguration;
use App\Models\AiUsageLog;
use App\Services\AI\AiConfigurationService;
use App\Services\AI\AIEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;

class AiConfigController extends Controller
{
    private AiConfigurationService $configService;

    public function __construct(AiConfigurationService $configService)
    {
        $this->configService = $configService;
    }

    /**
     * GET /api/ai/config
     * List all AI configurations (API keys masked).
     */
    public function index()
    {
        $configs = AiConfiguration::orderBy('sort_order')->orderBy('id')->get();

        return response()->json($configs->map(fn ($c) => $c->toArray()));
    }

    /**
     * POST /api/ai/config
     * Create a new AI configuration.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'provider'   => ['required', 'string', Rule::in(['openai', 'anthropic', 'gemini'])],
            'model'      => ['required', 'string', 'max:100'],
            'apiKey'     => ['required', 'string', 'min:8'],
            'baseUrl'    => ['nullable', 'string', 'max:255'],
            'maxTokens'  => ['nullable', 'integer', 'min:1', 'max:100000'],
            'isActive'   => ['sometimes', 'boolean'],
            'isFallback' => ['sometimes', 'boolean'],
            'sortOrder'  => ['sometimes', 'integer', 'min:0'],
        ]);

        // Unique (provider, model)
        $exists = AiConfiguration::where('provider', $data['provider'])
            ->where('model', $data['model'])
            ->exists();

        if ($exists) {
            return response()->json([
                'message' => "El modelo \"{$data['model']}\" ya existe para el proveedor \"{$data['provider']}\".",
            ], 422);
        }

        $config = AiConfiguration::create([
            'provider'    => $data['provider'],
            'model'       => $data['model'],
            'api_key'     => $data['apiKey'],
            'base_url'    => $data['baseUrl'] ?? null,
            'max_tokens'  => $data['maxTokens'] ?? 4096,
            'is_active'   => $data['isActive'] ?? true,
            'is_fallback' => $data['isFallback'] ?? false,
            'sort_order'  => $data['sortOrder'] ?? 0,
        ]);

        $this->configService->syncToCache();

        return response()->json($config->toArray(), 201);
    }

    /**
     * GET /api/ai/config/{id}
     * Show a single configuration (API key masked).
     */
    public function show(int $id)
    {
        $config = AiConfiguration::findOrFail($id);
        return response()->json($config->toArray());
    }

    /**
     * PATCH /api/ai/config/{id}
     * Update a configuration.
     */
    public function update(Request $request, int $id)
    {
        $config = AiConfiguration::findOrFail($id);

        $data = $request->validate([
            'model'      => ['sometimes', 'string', 'max:100'],
            'apiKey'     => ['sometimes', 'string', 'min:8'],
            'baseUrl'    => ['nullable', 'string', 'max:255'],
            'maxTokens'  => ['nullable', 'integer', 'min:1', 'max:100000'],
            'isActive'   => ['sometimes', 'boolean'],
            'isFallback' => ['sometimes', 'boolean'],
            'sortOrder'  => ['sometimes', 'integer', 'min:0'],
        ]);

        // Check unique if model changed
        if (isset($data['model']) && $data['model'] !== $config->model) {
            $conflict = AiConfiguration::where('provider', $config->provider)
                ->where('model', $data['model'])
                ->where('id', '!=', $id)
                ->exists();

            if ($conflict) {
                return response()->json([
                    'message' => "El modelo \"{$data['model']}\" ya existe para el proveedor \"{$config->provider}\".",
                ], 422);
            }
        }

        $updateData = [];
        if (isset($data['model']))      $updateData['model'] = $data['model'];
        if (isset($data['apiKey']))     $updateData['api_key'] = $data['apiKey'];
        if (array_key_exists('baseUrl', $data))   $updateData['base_url'] = $data['baseUrl'];
        if (array_key_exists('maxTokens', $data)) $updateData['max_tokens'] = $data['maxTokens'];
        if (isset($data['isActive']))   $updateData['is_active'] = $data['isActive'];
        if (isset($data['isFallback'])) $updateData['is_fallback'] = $data['isFallback'];
        if (isset($data['sortOrder']))  $updateData['sort_order'] = $data['sortOrder'];

        $config->update($updateData);

        $this->configService->syncToCache();

        return response()->json($config->fresh()->toArray());
    }

    /**
     * DELETE /api/ai/config/{id}
     * Delete a configuration.
     */
    public function destroy(int $id)
    {
        $config = AiConfiguration::findOrFail($id);
        $config->delete();

        $this->configService->syncToCache();

        return response()->json(['message' => 'Configuración eliminada.'], 200);
    }

    /**
     * POST /api/ai/config/{id}/test
     * Health check: make a lightweight API call to validate credentials.
     */
    public function test(int $id)
    {
        $config = AiConfiguration::findOrFail($id);
        $apiKey = $config->api_key; // decrypted by accessor

        if (empty($apiKey)) {
            return response()->json(['success' => false, 'message' => 'API Key no configurada.'], 400);
        }

        $result = match ($config->provider) {
            'openai'    => $this->testOpenAI($apiKey, $config->model),
            'anthropic' => $this->testAnthropic($apiKey, $config->model),
            'gemini'    => $this->testGemini($apiKey, $config->model),
            default     => ['success' => false, 'message' => 'Proveedor no soportado.'],
        };

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * GET /api/ai/config/usage
     * Aggregated usage analytics.
     */
    public function usage(Request $request)
    {
        $days = (int) $request->query('days', 30);
        $days = max(1, min(365, $days));

        $since = now()->subDays($days);

        // Daily aggregation
        $daily = AiUsageLog::where('created_at', '>=', $since)
            ->selectRaw("DATE(created_at) as date")
            ->selectRaw("SUM(prompt_tokens) as prompt_tokens")
            ->selectRaw("SUM(completion_tokens) as completion_tokens")
            ->selectRaw("SUM(total_tokens) as total_tokens")
            ->selectRaw("SUM(cost_estimate) as cost")
            ->selectRaw("COUNT(*) as requests")
            ->selectRaw("SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END) as successful_requests")
            ->selectRaw("SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END) as failed_requests")
            ->groupByRaw("DATE(created_at)")
            ->orderBy('date')
            ->get();

        // By provider
        $byProvider = AiUsageLog::where('created_at', '>=', $since)
            ->selectRaw("provider")
            ->selectRaw("SUM(prompt_tokens) as prompt_tokens")
            ->selectRaw("SUM(completion_tokens) as completion_tokens")
            ->selectRaw("SUM(total_tokens) as total_tokens")
            ->selectRaw("SUM(cost_estimate) as cost")
            ->selectRaw("COUNT(*) as requests")
            ->groupBy('provider')
            ->get();

        // By provider + model
        $byModel = AiUsageLog::where('created_at', '>=', $since)
            ->selectRaw("provider, model")
            ->selectRaw("SUM(prompt_tokens) as prompt_tokens")
            ->selectRaw("SUM(completion_tokens) as completion_tokens")
            ->selectRaw("SUM(total_tokens) as total_tokens")
            ->selectRaw("SUM(cost_estimate) as cost")
            ->selectRaw("COUNT(*) as requests")
            ->groupByRaw("provider, model")
            ->orderBy('provider')
            ->get();

        // Totals
        $totals = AiUsageLog::where('created_at', '>=', $since)
            ->selectRaw("COALESCE(SUM(prompt_tokens), 0) as prompt_tokens")
            ->selectRaw("COALESCE(SUM(completion_tokens), 0) as completion_tokens")
            ->selectRaw("COALESCE(SUM(total_tokens), 0) as total_tokens")
            ->selectRaw("COALESCE(SUM(cost_estimate), 0) as total_cost")
            ->selectRaw("COUNT(*) as total_requests")
            ->selectRaw("COALESCE(SUM(CASE WHEN success = 1 THEN 1 ELSE 0 END), 0) as successful_requests")
            ->selectRaw("COALESCE(SUM(CASE WHEN success = 0 THEN 1 ELSE 0 END), 0) as failed_requests")
            ->first();

        return response()->json([
            'daily'      => $daily ?? [],
            'byProvider' => $byProvider ?? [],
            'byModel'    => $byModel ?? [],
            'totals'     => $totals ?? [
                'prompt_tokens'     => 0,
                'completion_tokens' => 0,
                'total_tokens'      => 0,
                'total_cost'        => 0,
                'total_requests'    => 0,
                'successful_requests' => 0,
                'failed_requests'   => 0,
            ],
        ]);
    }

    /**
     * POST /api/ai/config/sync
     * Persist current DB config to runtime cache so AIEvaluationService
     * picks up changes without server restart.
     */
    public function sync()
    {
        $this->configService->syncToCache();

        $count = AiConfiguration::active()->count();

        return response()->json([
            'message' => "Configuración sincronizada. {$count} configuración(es) activa(s).",
            'activeConfigs' => $count,
        ]);
    }

    // ── Health check helpers ──

    private function testOpenAI(string $apiKey, string $model): array
    {
        try {
            $response = Http::timeout(10)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'    => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => 'Respond with "ok"'],
                    ],
                    'max_tokens' => 5,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Conexión exitosa con OpenAI.'];
            }

            $body = $response->json();
            $error = $body['error']['message'] ?? $response->body();

            return ['success' => false, 'message' => "OpenAI: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => "OpenAI: {$e->getMessage()}"];
        }
    }

    private function testAnthropic(string $apiKey, string $model): array
    {
        try {
            $response = Http::timeout(10)
                ->withHeaders([
                    'x-api-key'         => $apiKey,
                    'anthropic-version' => '2023-06-01',
                ])
                ->post('https://api.anthropic.com/v1/messages', [
                    'model'      => $model,
                    'messages'   => [
                        ['role' => 'user', 'content' => 'Respond with "ok"'],
                    ],
                    'max_tokens' => 5,
                ]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Conexión exitosa con Anthropic.'];
            }

            $body = $response->json();
            $error = $body['error']['message'] ?? $response->body();

            return ['success' => false, 'message' => "Anthropic: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => "Anthropic: {$e->getMessage()}"];
        }
    }

    private function testGemini(string $apiKey, string $model): array
    {
        try {
            $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent?key={$apiKey}";

            $response = Http::timeout(10)
                ->post($url, [
                    'contents' => [
                        [
                            'parts' => [
                                ['text' => 'Respond with "ok"'],
                            ],
                        ],
                    ],
                    'generationConfig' => [
                        'maxOutputTokens' => 5,
                    ],
                ]);

            if ($response->successful()) {
                return ['success' => true, 'message' => 'Conexión exitosa con Gemini.'];
            }

            $body = $response->json();
            $error = $body['error']['message'] ?? $response->body();

            return ['success' => false, 'message' => "Gemini: {$error}"];
        } catch (\Throwable $e) {
            return ['success' => false, 'message' => "Gemini: {$e->getMessage()}"];
        }
    }
}
