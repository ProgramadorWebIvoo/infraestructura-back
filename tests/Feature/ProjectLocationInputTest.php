<?php

namespace Tests\Feature;

use App\Models\Localization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F2-R R7c: la obra se crea con una ubicación registrada o con una personalizada, nunca ambas. */
class ProjectLocationInputTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;
    private Localization $localization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->creator = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $resident = User::factory()->create(['role' => 'RESIDENTE', 'name' => 'Rita']);
        $this->localization = Localization::create([
            'title' => 'Tienda Sur', 'city' => 'Valencia', 'type' => 'TIENDA', 'resident_user_id' => $resident->id,
        ]);
    }

    private function body(array $override = []): array
    {
        return array_merge([
            'title' => 'Obra nueva de prueba',
            'type' => 'INFRAESTRUCTURA',
            'description' => 'Descripción suficientemente larga de la obra',
            'materials' => [['name' => 'Cemento', 'quantity' => 5, 'unit' => 'Saco', 'estimatedUnitPrice' => 10, 'condition' => 'NUEVO']],
        ], $override);
    }

    public function test_registered_localization_sets_the_link_and_copies_the_label(): void
    {
        $response = $this->actingAs($this->creator)->postJson('/api/projects', $this->body(['localizationId' => $this->localization->id]))
            ->assertCreated()
            ->assertJsonPath('data.localizationId', $this->localization->id)
            ->assertJsonPath('data.location', 'Tienda Sur — Valencia')
            ->assertJsonPath('data.residentName', 'Rita');

        $project = Project::find($response->json('data.id'));
        $this->assertSame($this->localization->id, $project->localization_id);
        $this->assertNull($project->resident_user_id);
    }

    public function test_custom_location_keeps_free_text_without_resident(): void
    {
        $response = $this->actingAs($this->creator)->postJson('/api/projects', $this->body(['location' => 'Galpón alquilado en Maracay']))
            ->assertCreated()
            ->assertJsonPath('data.localizationId', null)
            ->assertJsonPath('data.location', 'Galpón alquilado en Maracay');

        $this->assertNull(Project::find($response->json('data.id'))->resident_user_id);
    }

    public function test_exactly_one_of_localization_or_location_is_required(): void
    {
        $this->actingAs($this->creator)->postJson('/api/projects', $this->body())->assertUnprocessable();
        $this->actingAs($this->creator)->postJson('/api/projects', $this->body([
            'localizationId' => $this->localization->id, 'location' => 'Otro lugar',
        ]))->assertUnprocessable();
    }

    public function test_inactive_or_unknown_localization_is_rejected(): void
    {
        $this->localization->update(['is_active' => false]);

        $this->actingAs($this->creator)->postJson('/api/projects', $this->body(['localizationId' => $this->localization->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('localizationId');
        $this->actingAs($this->creator)->postJson('/api/projects', $this->body(['localizationId' => 9999]))->assertUnprocessable();
    }

    public function test_the_creator_cannot_choose_a_resident(): void
    {
        $someone = User::factory()->create(['role' => 'RESIDENTE']);

        $response = $this->actingAs($this->creator)->postJson('/api/projects', $this->body([
            'localizationId' => $this->localization->id, 'residentUserId' => $someone->id,
        ]))->assertCreated();

        $this->assertNull(Project::find($response->json('data.id'))->resident_user_id);
    }

    public function test_resubmit_can_switch_between_registered_and_custom(): void
    {
        $project = Project::factory()->create([
            'requested_by_user_id' => $this->creator->id, 'status' => 'RECHAZADO_AUDITORIA', 'location' => 'Vieja',
        ]);

        $this->actingAs($this->creator)->postJson("/api/projects/{$project->id}/resubmit", $this->body(['localizationId' => $this->localization->id]))
            ->assertOk()->assertJsonPath('data.localizationId', $this->localization->id)->assertJsonPath('data.location', 'Tienda Sur — Valencia');

        Project::whereKey($project->id)->update(['status' => 'RECHAZADO_AUDITORIA']);
        $this->actingAs($this->creator)->postJson("/api/projects/{$project->id}/resubmit", $this->body(['location' => 'Sitio propio']))
            ->assertOk()->assertJsonPath('data.localizationId', null)->assertJsonPath('data.location', 'Sitio propio');
    }
}
