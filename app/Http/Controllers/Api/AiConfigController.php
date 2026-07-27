<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiConfiguration;
use App\Models\AiUsageLog;
use App\Services\AI\AiConfigurationService;
use App\Services\AI\AIEvaluationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
     * GET /api/ai/config/models
     * Modelos seleccionables por proveedor, para el selector del panel de admin.
     */
    public function availableModels()
    {
        return response()->json(config('ai.available_models', []));
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
            'baseUrl'    => ['nullable', 'string', 'max:255', $this->ssrfSafeUrl()],
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
            'baseUrl'    => ['nullable', 'string', 'max:255', $this->ssrfSafeUrl()],
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
            $url = "https://generativelanguage.googleapis.com/v1/models/{$model}:generateContent";

            $response = Http::timeout(10)
                ->withHeaders(['x-goog-api-key' => $apiKey])
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

    // ── SSRF protection ──

    /**
     * Valida que la URL no apunte a direcciones internas (SSRF prevention).
     * Solo permite URLs HTTPS a dominios públicos.
     */
    private function ssrfSafeUrl(): callable
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($value === null || $value === '') {
                return;
            }

            // Debe comenzar con https://
            if (!str_starts_with($value, 'https://')) {
                $fail($attribute, 'La URL debe usar HTTPS (conexión segura).');
                return;
            }

            $host = parse_url($value, PHP_URL_HOST);

            if ($host === false || $host === null || $host === '') {
                $fail($attribute, 'La URL no tiene un host válido.');
                return;
            }

            // Rejectar localhost / 127.0.0.1 / 0.0.0.0 / [::1]
            $localHosts = ['localhost', '127.0.0.1', '0.0.0.0', '::1', '[::1]'];
            if (in_array(strtolower($host), $localHosts, true)) {
                $fail($attribute, 'No se permite usar direcciones locales (localhost/127.0.0.1).');
                return;
            }

            // Rejectar IPs privadas (10.x.x.x, 172.16-31.x.x, 192.168.x.x)
            if (filter_var($host, FILTER_VALIDATE_IP)) {
                if (!filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $fail($attribute, 'No se permite usar IPs privadas o de rangos reservados.');
                    return;
                }
                return;
            }

            // El host es un dominio, no una IP literal: resolverlo y validar
            // TODAS las IPs que devuelve. Esto bloquea el caso obvio de un
            // dominio público apuntando a una IP privada/metadata de nube
            // (169.254.169.254, etc.). No protege contra "DNS rebinding" en
            // sentido estricto (TTL≈0, la IP cambia entre esta validación y
            // la request real del provider) — eso requeriría fijar la IP
            // resuelta a nivel de conexión HTTP (handler cURL/Guzzle custom),
            // fuera de alcance de esta validación de formulario.
            $resolvedIps = [];

            $aRecords = @dns_get_record($host, DNS_A);
            foreach ($aRecords ?: [] as $record) {
                if (!empty($record['ip'])) $resolvedIps[] = $record['ip'];
            }

            $aaaaRecords = @dns_get_record($host, DNS_AAAA);
            foreach ($aaaaRecords ?: [] as $record) {
                if (!empty($record['ipv6'])) $resolvedIps[] = $record['ipv6'];
            }

            if (empty($resolvedIps)) {
                $fail($attribute, 'No se pudo resolver el host de la URL.');
                return;
            }

            foreach (array_unique($resolvedIps) as $ip) {
                if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    $fail($attribute, 'El host de la URL resuelve a una dirección IP privada o reservada.');
                    return;
                }
            }
        };
    }
}
