<?php

namespace Tests\Feature;

use App\Models\Localization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** F2-R R7b: residente efectivo (ubicación registrada en vivo / personalizada) y su visibilidad. */
class EffectiveResidentTest extends TestCase
{
    use RefreshDatabase;

    private User $rita;
    private User $omar;
    private Localization $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->rita = User::factory()->create(['role' => 'RESIDENTE', 'name' => 'Rita']);
        $this->omar = User::factory()->create(['role' => 'RESIDENTE', 'name' => 'Omar']);
        $this->store = Localization::create([
            'title' => 'Tienda Sur', 'city' => 'Valencia', 'type' => 'TIENDA', 'resident_user_id' => $this->rita->id,
        ]);

        // El middleware se prueba con una ruta de la forma que usará R3 (`resident/projects/{project}`).
        Route::middleware(['api', 'auth:sanctum', 'project.access'])
            ->get('/api/resident/projects/{project}', fn (Project $project) => response()->json(['id' => $project->id]));
    }

    private function registered(string $id = 'PRJ-801'): Project
    {
        return Project::factory()->create(['id' => $id, 'localization_id' => $this->store->id, 'resident_user_id' => null]);
    }

    private function custom(string $id = 'PRJ-802'): Project
    {
        return Project::factory()->create(['id' => $id, 'localization_id' => null, 'resident_user_id' => $this->omar->id]);
    }

    public function test_registered_project_inherits_the_localization_resident(): void
    {
        $project = $this->registered();

        $this->assertSame($this->rita->id, $project->effectiveResidentId());
        $this->assertSame('Rita', $project->effectiveResident()->name);
    }

    public function test_custom_project_uses_its_own_resident(): void
    {
        $project = $this->custom();

        $this->assertSame($this->omar->id, $project->effectiveResidentId());
        $this->assertNull($this->custom('PRJ-803')->fresh()->localization_id);
    }

    public function test_changing_the_localization_resident_moves_open_projects_live(): void
    {
        $project = $this->registered();
        $this->store->update(['resident_user_id' => $this->omar->id]);

        $this->assertSame($this->omar->id, $project->fresh()->effectiveResidentId());
    }

    public function test_project_without_any_resident_has_none(): void
    {
        $project = Project::factory()->create(['id' => 'PRJ-804']);

        $this->assertNull($project->effectiveResidentId());
        $this->assertNull($project->effectiveResident());
    }

    public function test_scope_selects_projects_from_both_origins(): void
    {
        $this->registered('PRJ-811');
        $this->custom('PRJ-812');
        Project::factory()->create(['id' => 'PRJ-813', 'resident_user_id' => $this->rita->id, 'localization_id' => $this->store->id]);

        $this->assertEqualsCanonicalizing(['PRJ-811', 'PRJ-813'], Project::whereEffectiveResident($this->rita->id)->pluck('id')->all());
        $this->assertSame(['PRJ-812'], Project::whereEffectiveResident($this->omar->id)->pluck('id')->all());
    }

    public function test_the_localization_resident_wins_over_a_stale_project_resident(): void
    {
        // Una obra registrada ignora su propio resident_user_id (solo cuenta el de la ubicación).
        Project::factory()->create(['id' => 'PRJ-821', 'localization_id' => $this->store->id, 'resident_user_id' => $this->omar->id]);

        $this->assertSame([], Project::whereEffectiveResident($this->omar->id)->pluck('id')->all());
    }

    public function test_resource_exposes_localization_and_effective_resident(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $registered = $this->registered();
        $custom = $this->custom();

        $body = $this->actingAs($admin)->getJson("/api/projects/{$registered->id}")->assertOk();
        $body->assertJsonPath('data.localizationId', $this->store->id)
            ->assertJsonPath('data.localizationTitle', 'Tienda Sur — Valencia')
            ->assertJsonPath('data.residentUserId', $this->rita->id)
            ->assertJsonPath('data.residentName', 'Rita');

        $this->actingAs($admin)->getJson("/api/projects/{$custom->id}")->assertOk()
            ->assertJsonPath('data.localizationId', null)
            ->assertJsonPath('data.residentName', 'Omar');
    }

    public function test_resource_follows_a_resident_change_of_the_localization(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $project = $this->registered();
        $this->store->update(['resident_user_id' => $this->omar->id]);

        $this->actingAs($admin)->getJson("/api/projects/{$project->id}")->assertJsonPath('data.residentName', 'Omar');
    }

    public function test_project_list_carries_the_effective_resident(): void
    {
        $admin = User::factory()->create(['role' => 'ADMIN']);
        $this->registered();
        $this->custom();

        $names = collect($this->actingAs($admin)->getJson('/api/projects')->assertOk()->json('data'))->pluck('residentName', 'id');

        $this->assertSame('Rita', $names['PRJ-801']);
        $this->assertSame('Omar', $names['PRJ-802']);
    }

    public function test_residente_only_reaches_projects_where_it_is_the_effective_resident(): void
    {
        $registered = $this->registered();
        $custom = $this->custom();

        $this->actingAs($this->rita)->getJson("/api/resident/projects/{$registered->id}")->assertOk();
        $this->actingAs($this->rita)->getJson("/api/resident/projects/{$custom->id}")->assertNotFound();
        $this->actingAs($this->omar)->getJson("/api/resident/projects/{$custom->id}")->assertOk();
        $this->actingAs($this->omar)->getJson("/api/resident/projects/{$registered->id}")->assertNotFound();
    }

    public function test_previous_resident_loses_access_when_the_localization_changes(): void
    {
        $project = $this->registered();
        $this->store->update(['resident_user_id' => $this->omar->id]);

        $this->actingAs($this->rita)->getJson("/api/resident/projects/{$project->id}")->assertNotFound();
        $this->actingAs($this->omar)->getJson("/api/resident/projects/{$project->id}")->assertOk();
    }

    public function test_residente_still_cannot_use_regular_project_routes(): void
    {
        $registered = $this->registered();

        $this->actingAs($this->rita)->getJson("/api/projects/{$registered->id}")->assertForbidden();
        $this->actingAs($this->rita)->getJson('/api/projects')->assertForbidden();
    }
}
