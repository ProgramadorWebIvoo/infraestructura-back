<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private User $procura;
    private User $infraestructura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procura = User::factory()->create(['role' => 'PROCURA']);
        $this->infraestructura = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function evaluationPayload(Project $project, Contractor $contractor): array
    {
        return [
            'projectId' => $project->id,
            'projectTitle' => $project->title,
            'projectDescription' => $project->description,
            'projectLocation' => $project->location,
            'projectType' => $project->type,
            'approvedInvestmentAmount' => 50000,
            'proposals' => [
                [
                    'id' => 'PROP-TEST-1',
                    'contractorCode' => $contractor->code,
                    'contractorName' => $contractor->name,
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

    public function test_evaluate_succeeds_with_active_provider_and_logs_audit(): void
    {
        AiConfiguration::create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key-1234',
            'is_active' => true,
            'sort_order' => 0,
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'winnerContractorCode' => 'CON-1',
                        'winnerContractorName' => 'Constructora Test',
                        'confidenceScore' => 88,
                        'summary' => 'Resumen de prueba',
                        'strengths' => ['Precio competitivo'],
                        'weaknesses' => [],
                        'riskFactors' => [],
                        'recommendation' => 'Adjudicar',
                    ])]],
                ],
                'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 50, 'total_tokens' => 150],
            ], 200),
        ]);

        $project = Project::factory()->create();
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);

        $response = $this->withHeaders($this->headers($this->procura))
            ->postJson('/api/ai/evaluate-proposals', $this->evaluationPayload($project, $contractor));

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.winnerContractorCode', 'CON-1');
        $response->assertJsonPath('data.providerUsed', 'openai');

        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id,
            'role' => 'PROCURA',
        ]);
        $this->assertStringContainsString('Constructora Test', AuditLog::first()->action);
    }

    public function test_evaluate_returns_503_when_no_provider_configured(): void
    {
        $project = Project::factory()->create();
        $contractor = Contractor::factory()->create();

        $response = $this->withHeaders($this->headers($this->procura))
            ->postJson('/api/ai/evaluate-proposals', $this->evaluationPayload($project, $contractor));

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
    }

    public function test_evaluate_accepts_all_three_valid_providers_in_validation(): void
    {
        // Regresión: Rule::in('chatgpt', 'gemini', 'claude') sin envolver en
        // array solo validaba contra 'chatgpt', rechazando 'gemini'/'claude'
        // aunque fueran valores legítimos.
        $project = Project::factory()->create();
        $contractor = Contractor::factory()->create();

        foreach (['chatgpt', 'gemini', 'claude'] as $provider) {
            $payload = $this->evaluationPayload($project, $contractor);
            $payload['provider'] = $provider;

            $response = $this->withHeaders($this->headers($this->procura))
                ->postJson('/api/ai/evaluate-proposals', $payload);

            $response->assertJsonMissingValidationErrors('provider');
        }
    }

    public function test_evaluate_rejects_invalid_provider_value(): void
    {
        $project = Project::factory()->create();
        $contractor = Contractor::factory()->create();

        $payload = $this->evaluationPayload($project, $contractor);
        $payload['provider'] = 'not-a-provider';

        $response = $this->withHeaders($this->headers($this->procura))
            ->postJson('/api/ai/evaluate-proposals', $payload);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('provider');
    }

    public function test_role_without_permission_cannot_evaluate(): void
    {
        $project = Project::factory()->create();
        $contractor = Contractor::factory()->create();

        $response = $this->withHeaders($this->headers($this->infraestructura))
            ->postJson('/api/ai/evaluate-proposals', $this->evaluationPayload($project, $contractor));

        $response->assertStatus(403);
    }
}
