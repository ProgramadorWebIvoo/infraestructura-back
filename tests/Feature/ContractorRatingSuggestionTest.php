<?php

namespace Tests\Feature;

use App\Models\AiConfiguration;
use App\Models\Contractor;
use App\Models\User;
use App\Services\AiFeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ContractorRatingSuggestionTest extends TestCase
{
    use RefreshDatabase;

    private function headers(User $user): array
    {
        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    private function fakeOpenAiSuccess(): void
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
                        'suggestedRating' => 4.3,
                        'confidenceScore' => 60,
                        'rationale' => 'Historial estable, sin alertas relevantes.',
                    ])]],
                ],
                'usage' => ['prompt_tokens' => 80, 'completion_tokens' => 40, 'total_tokens' => 120],
            ], 200),
        ]);
    }

    public function test_admin_gets_a_rating_suggestion(): void
    {
        $this->fakeOpenAiSuccess();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $contractor = Contractor::factory()->create(['code' => 'CON-1', 'rating' => 4.0]);

        $response = $this->withHeaders($this->headers($admin))
            ->getJson("/api/contractors/{$contractor->code}/rating-suggestion");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.suggestedRating', 4.3);
    }

    public function test_role_without_permission_cannot_request_a_suggestion(): void
    {
        $procura = User::factory()->create(['role' => 'PROCURA']);
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);

        $response = $this->withHeaders($this->headers($procura))
            ->getJson("/api/contractors/{$contractor->code}/rating-suggestion");

        $response->assertStatus(403);
    }

    public function test_suggestion_is_blocked_when_catalogos_department_gate_is_disabled(): void
    {
        AiFeatureGate::setDepartmentEnabled('CATALOGOS', false);

        $admin = User::factory()->create(['role' => 'ADMIN']);
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);

        $response = $this->withHeaders($this->headers($admin))
            ->getJson("/api/contractors/{$contractor->code}/rating-suggestion");

        $response->assertStatus(403);
    }

    public function test_suggestion_is_blocked_when_the_specific_action_gate_is_disabled(): void
    {
        AiFeatureGate::setActionEnabled('CATALOGOS', 'ia.proveedores.sugerencia_rating', false);

        $admin = User::factory()->create(['role' => 'ADMIN']);
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);

        $response = $this->withHeaders($this->headers($admin))
            ->getJson("/api/contractors/{$contractor->code}/rating-suggestion");

        $response->assertStatus(403);
    }

    public function test_suggestion_does_not_modify_the_actual_rating(): void
    {
        $this->fakeOpenAiSuccess();
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $contractor = Contractor::factory()->create(['code' => 'CON-1', 'rating' => 4.0]);

        $this->withHeaders($this->headers($admin))
            ->getJson("/api/contractors/{$contractor->code}/rating-suggestion")
            ->assertStatus(200);

        $this->assertEquals(4.0, $contractor->fresh()->rating);
    }

    public function test_returns_503_when_no_provider_configured(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $contractor = Contractor::factory()->create(['code' => 'CON-1']);

        $response = $this->withHeaders($this->headers($admin))
            ->getJson("/api/contractors/{$contractor->code}/rating-suggestion");

        $response->assertStatus(503);
        $response->assertJsonPath('success', false);
    }
}
