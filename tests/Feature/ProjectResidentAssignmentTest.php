<?php

namespace Tests\Feature;

use App\Models\Localization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F2-R R7d: Auditoría fija y cambia el residente de las obras de ubicación personalizada. */
class ProjectResidentAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private User $auditoria;
    private User $infra;
    private User $rita;
    private User $omar;
    private Localization $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->auditoria = User::factory()->create(['role' => 'AUDITORIA']);
        $this->infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->rita = User::factory()->create(['role' => 'RESIDENTE', 'name' => 'Rita']);
        $this->omar = User::factory()->create(['role' => 'RESIDENTE', 'name' => 'Omar']);
        $this->store = Localization::create([
            'title' => 'Tienda Sur', 'city' => 'Valencia', 'type' => 'TIENDA', 'resident_user_id' => $this->rita->id,
        ]);
    }

    private function custom(string $status = 'CREADO', array $extra = []): Project
    {
        return Project::factory()->create(array_merge([
            'requested_by_user_id' => $this->infra->id, 'status' => $status, 'localization_id' => null, 'resident_user_id' => null,
        ], $extra));
    }

    private function registered(): Project
    {
        return Project::factory()->create([
            'requested_by_user_id' => $this->infra->id, 'status' => 'CREADO', 'localization_id' => $this->store->id,
        ]);
    }

    // ── review ──────────────────────────────────────────────────────────────

    public function test_review_of_a_custom_project_requires_a_resident(): void
    {
        $project = $this->custom();

        $this->actingAs($this->auditoria)->postJson("/api/projects/{$project->id}/review", [])
            ->assertUnprocessable()->assertJsonValidationErrors('residentUserId');
        $this->assertSame('CREADO', $project->fresh()->status);
    }

    public function test_review_of_a_custom_project_stores_the_chosen_resident_and_audits_it(): void
    {
        $project = $this->custom();

        $this->actingAs($this->auditoria)->postJson("/api/projects/{$project->id}/review", ['residentUserId' => $this->omar->id])
            ->assertOk()->assertJsonPath('data.residentName', 'Omar')->assertJsonPath('data.status', 'REVISADO_AUDITORIA');

        $this->assertSame($this->omar->id, $project->fresh()->resident_user_id);
        $this->assertDatabaseHas('audit_logs', ['project_id' => $project->id, 'action' => 'Asignacion de residente']);
    }

    public function test_review_of_a_registered_project_forbids_choosing_a_resident(): void
    {
        $project = $this->registered();

        $this->actingAs($this->auditoria)->postJson("/api/projects/{$project->id}/review", ['residentUserId' => $this->omar->id])
            ->assertUnprocessable();
        $this->assertSame('CREADO', $project->fresh()->status);

        $this->actingAs($this->auditoria)->postJson("/api/projects/{$project->id}/review", [])
            ->assertOk()->assertJsonPath('data.residentName', 'Rita');
    }

    public function test_review_rejects_non_residente_or_inactive_users(): void
    {
        $project = $this->custom();
        $inactive = User::factory()->create(['role' => 'RESIDENTE', 'status' => 'Inactive']);

        foreach ([$this->infra->id, $inactive->id] as $userId) {
            $this->actingAs($this->auditoria)->postJson("/api/projects/{$project->id}/review", ['residentUserId' => $userId])->assertUnprocessable();
        }
        $this->assertSame('CREADO', $project->fresh()->status);
    }

    // ── PATCH resident ──────────────────────────────────────────────────────

    public function test_auditoria_changes_the_resident_of_a_custom_project_with_reason(): void
    {
        $project = $this->custom('EN_EJECUCION', ['resident_user_id' => $this->rita->id]);

        $this->actingAs($this->auditoria)->patchJson("/api/projects/{$project->id}/resident", ['residentUserId' => $this->omar->id, 'reason' => 'Rita de licencia'])
            ->assertOk()->assertJsonPath('data.residentName', 'Omar');

        $this->assertSame($this->omar->id, $project->fresh()->resident_user_id);
        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id, 'action' => 'Cambio de residente de obra', 'details' => 'de Rita a Omar.', 'observations' => 'Rita de licencia',
        ]);
    }

    public function test_reason_is_mandatory(): void
    {
        $project = $this->custom('EN_EJECUCION', ['resident_user_id' => $this->rita->id]);

        $this->actingAs($this->auditoria)->patchJson("/api/projects/{$project->id}/resident", ['residentUserId' => $this->omar->id])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_change_is_allowed_up_to_informe_enviado_only(): void
    {
        $open = $this->custom('INFORME_ENVIADO', ['resident_user_id' => $this->rita->id]);
        $late = $this->custom('VERIFICANDO_FINALIZACION', ['resident_user_id' => $this->rita->id]);
        $rejected = $this->custom('RECHAZADO_AUDITORIA');
        $body = ['residentUserId' => $this->omar->id, 'reason' => 'Cambio'];

        $this->actingAs($this->auditoria)->patchJson("/api/projects/{$open->id}/resident", $body)->assertOk();
        $this->actingAs($this->auditoria)->patchJson("/api/projects/{$late->id}/resident", $body)->assertUnprocessable();
        $this->actingAs($this->auditoria)->patchJson("/api/projects/{$rejected->id}/resident", $body)->assertUnprocessable();
    }

    public function test_registered_projects_cannot_change_resident_from_the_project(): void
    {
        $project = $this->registered();

        $this->actingAs($this->auditoria)->patchJson("/api/projects/{$project->id}/resident", ['residentUserId' => $this->omar->id, 'reason' => 'Cambio'])
            ->assertUnprocessable();
        $this->assertNull($project->fresh()->resident_user_id);
    }

    public function test_same_resident_and_invalid_users_are_rejected(): void
    {
        $project = $this->custom('EN_EJECUCION', ['resident_user_id' => $this->rita->id]);
        $url = "/api/projects/{$project->id}/resident";

        $this->actingAs($this->auditoria)->patchJson($url, ['residentUserId' => $this->rita->id, 'reason' => 'Igual'])->assertUnprocessable();
        $this->actingAs($this->auditoria)->patchJson($url, ['residentUserId' => $this->infra->id, 'reason' => 'Cambio'])->assertUnprocessable();
    }

    public function test_only_auditoria_and_admins_may_change_the_resident(): void
    {
        $project = $this->custom('EN_EJECUCION', ['resident_user_id' => $this->rita->id]);
        $body = ['residentUserId' => $this->omar->id, 'reason' => 'Cambio'];

        // El creador ya no asigna ni reasigna residente (D9); a RESIDENTE el middleware le niega el acceso.
        $this->actingAs($this->infra)->patchJson("/api/projects/{$project->id}/resident", $body)->assertForbidden();
        $this->actingAs($this->rita)->patchJson("/api/projects/{$project->id}/resident", $body)->assertForbidden();
        foreach (['ADMIN', 'SUPERADMIN'] as $role) {
            $this->actingAs(User::factory()->create(['role' => $role]))->patchJson("/api/projects/{$project->id}/resident", $body)->assertOk();
            Project::whereKey($project->id)->update(["resident_user_id" => $this->rita->id]);
        }
    }
}
