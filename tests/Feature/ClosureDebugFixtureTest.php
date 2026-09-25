<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClosureDebugFixtureTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();
        config(['app.debug' => true]);
    }

    public function test_superadmin_creates_fixture_at_each_step(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $response = $this->actingAs($admin)->postJson('/api/debug/closure-fixtures', ['targetStatus' => 'PENDIENTE_SOLICITUD_FINIQUITO'])->assertStatus(201);
        $response->assertJsonPath('status', 'PENDIENTE_SOLICITUD_FINIQUITO');
        $this->assertNotNull($response->json('publicUrl'));

        $id = $response->json('projectId');
        $this->actingAs($admin)->postJson("/api/debug/closure-fixtures/{$id}/advance", ['targetStatus' => 'LISTO_PAGO_FINAL'])->assertJsonPath('status', 'LISTO_PAGO_FINAL');
        $this->actingAs($admin)->getJson('/api/debug/closure-fixtures')->assertJsonPath('0.projectId', $id);
    }

    public function test_fixture_starting_in_execution_leaves_report_open_for_contractor(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $token = basename($this->actingAs($admin)->postJson('/api/debug/closure-fixtures', ['targetStatus' => 'EN_EJECUCION'])->assertStatus(201)->json('publicUrl'));

        $this->getJson("/api/public/closures/{$token}")->assertOk()->assertJsonPath('editable', true);
    }

    public function test_only_admins_and_only_with_debug_enabled(): void
    {
        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($infra)->postJson('/api/debug/closure-fixtures', ['targetStatus' => 'EN_EJECUCION'])->assertStatus(403);

        config(['app.debug' => false]);
        $this->actingAs($admin)->postJson('/api/debug/closure-fixtures', ['targetStatus' => 'EN_EJECUCION'])->assertStatus(404);
    }

    public function test_cannot_advance_real_projects(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $project = Project::factory()->create(['status' => 'EN_EJECUCION', 'title' => 'Obra real']);

        $this->actingAs($admin)->postJson("/api/debug/closure-fixtures/{$project->id}/advance", ['targetStatus' => 'LISTO_PAGO_FINAL'])->assertStatus(403);
    }
}
