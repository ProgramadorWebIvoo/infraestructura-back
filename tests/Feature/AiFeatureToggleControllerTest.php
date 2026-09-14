<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AiFeatureGate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiFeatureToggleControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_index_is_open_to_any_authenticated_role(): void
    {
        $analista = User::factory()->create(['role' => 'ANALISTA']);

        $response = $this->actingAs($analista)->getJson('/api/ai/feature-toggles');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data' => ['departments', 'actions', 'matrix']]);
        $this->assertContains('PROCURA', $response->json('data.departments'));
    }

    public function test_update_requires_superadmin(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($admin)
            ->putJson('/api/ai/feature-toggles', ['department' => 'PROCURA', 'enabled' => false])
            ->assertStatus(403);
    }

    public function test_update_toggles_department_master_switch(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $response = $this->actingAs($superadmin)->putJson('/api/ai/feature-toggles', [
            'department' => 'PROCURA',
            'enabled' => false,
        ]);

        $response->assertStatus(200);
        $this->assertFalse(AiFeatureGate::isDepartmentEnabled('PROCURA'));
        $this->assertDatabaseHas('ai_feature_toggles', ['department' => 'PROCURA', 'action' => null, 'enabled' => false]);
    }

    public function test_update_toggles_a_specific_action(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $response = $this->actingAs($superadmin)->putJson('/api/ai/feature-toggles', [
            'department' => 'PROCURA',
            'action' => 'ia.procura.evaluacion_propuestas',
            'enabled' => false,
        ]);

        $response->assertStatus(200);
        $this->assertFalse(AiFeatureGate::isActionEnabled('PROCURA', 'ia.procura.evaluacion_propuestas'));
    }

    public function test_update_rejects_action_that_does_not_belong_to_the_department(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($superadmin)->putJson('/api/ai/feature-toggles', [
            'department' => 'ANALISTA',
            'action' => 'ia.procura.evaluacion_propuestas',
            'enabled' => false,
        ])->assertStatus(404);
    }

    public function test_update_rejects_unknown_department(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($superadmin)->putJson('/api/ai/feature-toggles', [
            'department' => 'MARKETING',
            'enabled' => false,
        ])->assertStatus(422);
    }

    public function test_update_is_recorded_in_config_audit_log(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($superadmin)->putJson('/api/ai/feature-toggles', [
            'department' => 'PROCURA',
            'enabled' => false,
        ])->assertStatus(200);

        $this->assertDatabaseHas('config_audit_logs', [
            'entity_type' => 'ai_feature_toggle',
            'action' => 'Modificacion de disponibilidad de IA por departamento',
        ]);
    }
}
