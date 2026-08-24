<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DossierEvaluationTest extends TestCase
{
    use RefreshDatabase;

    private User $cierreDeObra;
    private User $procura;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cierreDeObra = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $this->procura = User::factory()->create(['role' => 'PROCURA']);
    }

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function fakeOpenAiSuccess(): void
    {
        Http::fake([
            'api.openai.com/*' => Http::response([
                'choices' => [
                    ['message' => ['content' => json_encode([
                        'score' => 78,
                        'summary' => 'Resumen de prueba del expediente.',
                        'alerts' => ['Faltan planos de detalle.'],
                        'recommendation' => 'Proceder con reservas.',
                        'suggestedAmount' => 42000,
                        'completenessFactors' => [
                            'documentation' => 60,
                            'budgetConsistency' => 85,
                            'rejectionRisk' => 90,
                        ],
                    ])]],
                ],
                'usage' => ['prompt_tokens' => 120, 'completion_tokens' => 60, 'total_tokens' => 180],
            ], 200),
        ]);
    }

    public function test_cierre_de_obra_can_evaluate_a_created_project(): void
    {
        AiConfiguration::create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key-1234',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $this->fakeOpenAiSuccess();

        $project = Project::factory()->create();

        $response = $this->withHeaders($this->headers($this->cierreDeObra))
            ->postJson("/api/projects/{$project->id}/evaluate-dossier");

        $response->assertStatus(200);
        $response->assertJsonPath('data.dossierAiScore', 78);
        $response->assertJsonPath('data.dossierAiSuggestedAmount', 42000);
        $response->assertJsonPath('data.dossierAiProvider', 'openai');

        $project->refresh();
        $this->assertSame(78, $project->dossier_ai_score);
        $this->assertNotNull($project->dossier_ai_evaluated_at);

        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id,
            'role' => 'CIERRE_DE_OBRA',
            'action' => 'Evaluacion inteligente de expediente',
        ]);
    }

    public function test_procura_cannot_evaluate_dossier(): void
    {
        $project = Project::factory()->create();

        $response = $this->withHeaders($this->headers($this->procura))
            ->postJson("/api/projects/{$project->id}/evaluate-dossier");

        $response->assertStatus(403);
    }

    public function test_returns_503_when_no_provider_configured(): void
    {
        $project = Project::factory()->create();

        $response = $this->withHeaders($this->headers($this->cierreDeObra))
            ->postJson("/api/projects/{$project->id}/evaluate-dossier");

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);

        $project->refresh();
        $this->assertNull($project->dossier_ai_evaluated_at);
    }

    public function test_rejected_when_project_already_revisado_cierre(): void
    {
        $project = Project::factory()->reviewed()->create();

        $response = $this->withHeaders($this->headers($this->cierreDeObra))
            ->postJson("/api/projects/{$project->id}/evaluate-dossier");

        $response->assertStatus(422);
    }

    public function test_allowed_when_project_is_rechazado_cierre(): void
    {
        AiConfiguration::create([
            'provider' => 'openai',
            'model' => 'gpt-4o-mini',
            'api_key' => 'sk-test-key-1234',
            'is_active' => true,
            'sort_order' => 0,
        ]);
        $this->fakeOpenAiSuccess();

        $project = Project::factory()->create(['status' => 'RECHAZADO_CIERRE']);

        $response = $this->withHeaders($this->headers($this->cierreDeObra))
            ->postJson("/api/projects/{$project->id}/evaluate-dossier");

        $response->assertStatus(200);
    }
}
