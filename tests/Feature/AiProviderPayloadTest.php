<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Services\AI\AIEvaluationService;
use App\Services\AI\Providers\AnthropicProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifica que los payloads enviados a los proveedores de IA sigan la
 * documentación oficial vigente:
 *  - OpenAI: max_completion_tokens (max_tokens deprecado en modelos modernos)
 *  - Anthropic: sin temperature (no soportada en modelos nuevos) + headers
 *  - Gemini: x-goog-api-key en header + generationConfig.maxOutputTokens
 */
class AiProviderPayloadTest extends TestCase
{
    use RefreshDatabase;

    private function aiPayload(): array
    {
        return [
            'project' => [
                'projectId' => 'P-1',
                'projectTitle' => 'Proyecto de prueba',
                'projectDescription' => 'Construcción de puente vehicular',
                'projectLocation' => 'CDMX',
                'projectType' => 'Obra pública',
                'approvedInvestmentAmount' => 50000,
            ],
            'proposals' => [
                [
                    'contractorCode' => 'CON-1',
                    'contractorName' => 'Constructora de Prueba',
                    'contractorRating' => 4.5,
                    'materialCost' => 20000,
                    'laborCost' => 10000,
                    'totalCost' => 30000,
                    'deliveryWeeks' => 6,
                    'negotiatedAdvancePercent' => 30,
                    'description' => 'Propuesta de prueba',
                ],
            ],
        ];
    }

    private function aiJson(): string
    {
        return json_encode([
            'winnerContractorCode' => 'CON-1',
            'winnerContractorName' => 'Constructora de Prueba',
            'confidenceScore' => 88,
            'summary' => 'Resumen de prueba',
            'strengths' => ['Precio competitivo'],
            'weaknesses' => [],
            'riskFactors' => [],
            'recommendation' => 'Adjudicar',
        ]);
    }

    private function evaluateWith(string $provider, string $model): void
    {
        AiConfiguration::create([
            'provider' => $provider,
            'model' => $model,
            'api_key' => 'sk-test-key-1234',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        $service = app(AIEvaluationService::class);
        $service->evaluateWithProvider($this->aiPayload(), $provider);
    }

    public function test_openai_payload_uses_max_completion_tokens(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [['message' => ['content' => $this->aiJson()]]],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ], 200),
        ]);

        $this->evaluateWith('openai', 'gpt-5.6-sol');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['model'] ?? null) === 'gpt-5.6-sol'
                && ($body['max_completion_tokens'] ?? null) === 4096
                && !array_key_exists('max_tokens', $body);
        });
    }

    public function test_anthropic_payload_omits_temperature_and_sends_required_headers(): void
    {
        Http::fake([
            'api.anthropic.com/*' => Http::response([
                'content' => [['text' => $this->aiJson()]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 50],
            ], 200),
        ]);

        $this->evaluateWith('anthropic', 'claude-sonnet-5');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['model'] ?? null) === 'claude-sonnet-5'
                && ($body['max_tokens'] ?? null) === 4096
                && !array_key_exists('temperature', $body)
                && $request->hasHeader('x-api-key', 'sk-test-key-1234')
                && $request->hasHeader('anthropic-version', AnthropicProvider::API_VERSION);
        });
    }

    public function test_gemini_payload_sends_key_in_header_and_generation_config(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => $this->aiJson()]]]]],
                'usageMetadata' => ['promptTokenCount' => 100, 'candidatesTokenCount' => 50, 'totalTokenCount' => 150],
            ], 200),
        ]);

        $this->evaluateWith('gemini', 'gemini-3.6-flash');

        Http::assertSent(function ($request) {
            $body = $request->data();

            return ($body['generationConfig']['maxOutputTokens'] ?? null) === 4096
                && $request->hasHeader('x-goog-api-key', 'sk-test-key-1234')
                && str_contains((string) $request->url(), ':generateContent');
        });
    }
}
