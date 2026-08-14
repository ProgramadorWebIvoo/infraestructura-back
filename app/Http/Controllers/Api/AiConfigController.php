<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAiConfigurationRequest;
use App\Http\Requests\UpdateAiConfigurationRequest;
use App\Http\Resources\AiConfigurationResource;
use App\Models\AiConfiguration;
use App\Models\ConfigAuditLog;
use App\Services\AI\AiConfigurationService;
use App\Services\AI\AIEvaluationService;
use App\Services\AI\AiUsageAnalyticsService;
use App\Services\AI\Providers\AIProviderFactory;
use Illuminate\Http\Request;

class AiConfigController extends Controller
{
    /**
     * Modelos seleccionables por proveedor para el selector del panel de admin.
     * Catálogo estático (no por BD): lista las opciones que el admin puede elegir.
     * Debe estar sincronizado con src/constants/aiModels.ts del frontend.
     */
    private const AVAILABLE_MODELS = [
        'openai' => ['gpt-5.6-sol', 'gpt-5.6-terra', 'gpt-5.6-luna', 'gpt-5.4-mini', 'gpt-5.4-nano', 'gpt-5.2', 'gpt-4.1', 'gpt-4.1-mini', 'o3', 'o4-mini'],
        'anthropic' => ['claude-opus-5', 'claude-opus-4-8', 'claude-sonnet-5', 'claude-sonnet-4-6', 'claude-haiku-4-5'],
        'gemini' => ['gemini-3.6-flash', 'gemini-3.1-pro-preview', 'gemini-3.5-flash', 'gemini-3.1-flash-lite', 'gemini-2.0-flash'],
    ];

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

        return response()->json(AiConfigurationResource::collection($configs));
    }

    /**
     * GET /api/ai/config/models
     * Modelos seleccionables por proveedor, para el selector del panel de admin.
     */
    public function availableModels()
    {
        return response()->json(self::AVAILABLE_MODELS);
    }

    /**
     * POST /api/ai/config
     * Create a new AI configuration.
     */
    public function store(StoreAiConfigurationRequest $request)
    {
        $data = $request->validated();

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

        $details = "Proveedor: {$config->provider} / Modelo: {$config->model}";
        $auditLog = ConfigAuditLog::recordAdminAction('ai_config', 'Alta de configuracion de IA', null, null, $details);

        // auditLog va como campo hermano del resource (no envuelto en su
        // propia clave "data") para no romper el contrato plano que ya
        // consume el frontend — mismo patrón que CurrencyController, pero
        // sin el wrap adicional porque este endpoint nunca respondió bajo
        // {data: ...} en primer lugar.
        return response()->json([
            ...(new AiConfigurationResource($config))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ], 201);
    }

    /**
     * GET /api/ai/config/{id}
     * Show a single configuration (API key masked).
     */
    public function show(AiConfiguration $aiConfig)
    {
        return response()->json(new AiConfigurationResource($aiConfig));
    }

    /**
     * PATCH /api/ai/config/{aiConfig}
     * Update a configuration.
     */
    public function update(UpdateAiConfigurationRequest $request, AiConfiguration $aiConfig)
    {
        $config = $aiConfig;

        $data = $request->validated();

        // Check unique if model changed
        if (isset($data['model']) && $data['model'] !== $config->model) {
            $conflict = AiConfiguration::where('provider', $config->provider)
                ->where('model', $data['model'])
                ->where('id', '!=', $config->id)
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

        $details = "Proveedor: {$config->provider} / Modelo: {$config->model}";
        $auditLog = ConfigAuditLog::recordAdminAction('ai_config', 'Modificacion de configuracion de IA', null, null, $details);

        return response()->json([
            ...(new AiConfigurationResource($config->fresh()))->resolve(),
            'auditLog' => $auditLog->toApiPayload(),
        ]);
    }

    /**
     * DELETE /api/ai/config/{id}
     * Delete a configuration.
     */
    public function destroy(AiConfiguration $aiConfig)
    {
        $details = "Proveedor: {$aiConfig->provider} / Modelo: {$aiConfig->model}";

        $aiConfig->delete();

        $this->configService->syncToCache();

        $auditLog = ConfigAuditLog::recordAdminAction('ai_config', 'Eliminacion de configuracion de IA', null, null, $details);

        return response()->json([
            'message' => 'Configuración eliminada.',
            'auditLog' => $auditLog->toApiPayload(),
        ], 200);
    }

    /**
     * POST /api/ai/config/{aiConfig}/test
     * Health check: make a lightweight API call to validate credentials.
     */
    public function test(AiConfiguration $aiConfig)
    {
        $config = $aiConfig;
        $apiKey = $config->api_key; // decrypted by accessor

        if (empty($apiKey)) {
            return response()->json(['success' => false, 'message' => 'API Key no configurada.'], 400);
        }

        if (!AIProviderFactory::supports($config->provider)) {
            return response()->json(['success' => false, 'message' => 'Proveedor no soportado.'], 400);
        }

        $provider = AIProviderFactory::make($config->provider, [
            'api_key' => $apiKey,
            'model'   => $config->model,
        ]);

        $result = $provider->healthCheck();

        return response()->json($result, $result['success'] ? 200 : 400);
    }

    /**
     * GET /api/ai/config/usage
     * Aggregated usage analytics.
     */
    public function usage(Request $request, AiUsageAnalyticsService $analytics)
    {
        $days = (int) $request->query('days', 30);
        $days = max(1, min(365, $days));

        return response()->json($analytics->getUsageSummary($days));
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

}
