<?php

namespace Tests\Feature;

use App\Models\Localization;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LocalizationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $superadmin;
    private User $resident;
    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'ADMIN']);
        $this->superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $this->resident = User::factory()->create(['role' => 'RESIDENTE', 'name' => 'Rita']);
        $this->other = User::factory()->create(['role' => 'RESIDENTE', 'name' => 'Omar']);
    }

    private function payload(array $override = []): array
    {
        return array_merge([
            'title' => 'Tienda Norte',
            'city' => 'Maracaibo',
            'type' => 'TIENDA',
            'residentUserId' => $this->resident->id,
        ], $override);
    }

    private function localization(array $override = []): Localization
    {
        return Localization::create(array_merge([
            'title' => 'Tienda Sur', 'city' => 'Valencia', 'type' => 'TIENDA', 'resident_user_id' => $this->resident->id,
        ], $override));
    }

    public function test_admin_and_superadmin_create_localizations(): void
    {
        foreach ([$this->admin, $this->superadmin] as $i => $user) {
            $this->actingAs($user)->postJson('/api/localizations', $this->payload(['title' => "Sede {$i}"]))
                ->assertCreated()
                ->assertJsonPath('residentName', 'Rita')
                ->assertJsonPath('isActive', true);
        }
        $this->assertDatabaseCount('localizations', 2);
    }

    public function test_other_roles_cannot_administer_localizations(): void
    {
        foreach (['INFRAESTRUCTURA', 'AUDITORIA', 'RESIDENTE'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->getJson('/api/localizations')->assertForbidden();
            $this->actingAs($user)->postJson('/api/localizations', $this->payload())->assertForbidden();
        }
    }

    public function test_resident_is_mandatory_and_must_be_an_active_residente(): void
    {
        $this->actingAs($this->admin)->postJson('/api/localizations', $this->payload(['residentUserId' => null]))->assertUnprocessable();

        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->actingAs($this->admin)->postJson('/api/localizations', $this->payload(['residentUserId' => $infra->id]))->assertUnprocessable();

        $inactive = User::factory()->create(['role' => 'RESIDENTE', 'status' => 'Inactive']);
        $this->actingAs($this->admin)->postJson('/api/localizations', $this->payload(['residentUserId' => $inactive->id]))->assertUnprocessable();
    }

    public function test_title_is_unique_per_city_but_repeatable_across_cities(): void
    {
        $this->actingAs($this->admin)->postJson('/api/localizations', $this->payload())->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/localizations', $this->payload())->assertUnprocessable()->assertJsonValidationErrors('title');
        $this->actingAs($this->admin)->postJson('/api/localizations', $this->payload(['city' => 'Caracas']))->assertCreated();
    }

    public function test_type_must_be_one_of_the_catalog(): void
    {
        $this->actingAs($this->admin)->postJson('/api/localizations', $this->payload(['type' => 'BODEGA']))->assertUnprocessable();
    }

    public function test_changing_the_resident_requires_a_reason_and_is_audited(): void
    {
        $loc = $this->localization();
        Project::factory()->create(['id' => 'PRJ-901', 'localization_id' => $loc->id, 'status' => 'EN_EJECUCION']);
        Project::factory()->create(['id' => 'PRJ-902', 'localization_id' => $loc->id, 'status' => 'COMPLETADO_PAGADO']);

        $this->actingAs($this->admin)->patchJson("/api/localizations/{$loc->id}", ['residentUserId' => $this->other->id])
            ->assertUnprocessable()->assertJsonValidationErrors('reason');

        $this->actingAs($this->admin)->patchJson("/api/localizations/{$loc->id}", ['residentUserId' => $this->other->id, 'reason' => 'Rotación'])
            ->assertOk()->assertJsonPath('residentName', 'Omar');

        $this->assertDatabaseHas('config_audit_logs', ['action' => 'Cambio de residente de ubicacion', 'entity_type' => 'localization']);
        $this->assertStringContainsString('Obras afectadas: 1', \App\Models\ConfigAuditLog::where('action', 'Cambio de residente de ubicacion')->value('new_value'));
    }

    public function test_editing_without_changing_the_resident_needs_no_reason(): void
    {
        $loc = $this->localization();

        $this->actingAs($this->admin)->patchJson("/api/localizations/{$loc->id}", ['notes' => 'Cerrada los lunes'])->assertOk();
        $this->assertSame('Cerrada los lunes', $loc->fresh()->notes);
    }

    public function test_localization_with_projects_cannot_be_deleted_only_deactivated(): void
    {
        $loc = $this->localization();
        Project::factory()->create(['id' => 'PRJ-903', 'localization_id' => $loc->id]);

        $this->actingAs($this->admin)->deleteJson("/api/localizations/{$loc->id}")->assertUnprocessable();
        $this->actingAs($this->admin)->postJson("/api/localizations/{$loc->id}/toggle-status")->assertOk()->assertJsonPath('isActive', false);
    }

    public function test_localization_without_projects_can_be_deleted(): void
    {
        $loc = $this->localization();

        $this->actingAs($this->admin)->deleteJson("/api/localizations/{$loc->id}")->assertOk();
        $this->assertDatabaseMissing('localizations', ['id' => $loc->id]);
    }

    public function test_active_list_excludes_inactive_and_is_open_to_creators_and_auditoria(): void
    {
        $this->localization();
        $this->localization(['title' => 'Cerrada', 'is_active' => false]);

        foreach (['INFRAESTRUCTURA', 'AUDITORIA', 'ADMIN'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $titles = collect($this->actingAs($user)->getJson('/api/localizations/active')->assertOk()->json('data'))->pluck('title');
            $this->assertSame(['Tienda Sur'], $titles->all());
        }
        $this->actingAs($this->resident)->getJson('/api/localizations/active')->assertForbidden();
    }

    public function test_residents_list_returns_active_residentes_only(): void
    {
        User::factory()->create(['role' => 'RESIDENTE', 'status' => 'Inactive']);
        User::factory()->create(['role' => 'INFRAESTRUCTURA']);

        $names = collect($this->actingAs($this->admin)->getJson('/api/residents')->assertOk()->json())->pluck('name')->sort()->values()->all();

        $this->assertSame(['Omar', 'Rita'], $names);
    }

    // ── Usuarios RESIDENTE gestionados por ADMIN ────────────────────────────

    public function test_admin_can_create_only_residente_users(): void
    {
        $body = ['name' => 'Nuevo', 'email' => 'nuevo@test.com', 'password' => 'Secreta123', 'password_confirmation' => 'Secreta123'];

        $this->actingAs($this->admin)->postJson('/api/users', $body + ['role' => 'RESIDENTE'])->assertCreated();
        $this->actingAs($this->admin)->postJson('/api/users', ['email' => 'otro@test.com'] + $body + ['role' => 'SUPERADMIN'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson('/api/users', ['email' => 'otro2@test.com'] + $body + ['role' => 'INFRAESTRUCTURA'])->assertUnprocessable();
    }

    public function test_admin_cannot_touch_non_residente_users(): void
    {
        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);

        $this->actingAs($this->admin)->patchJson("/api/users/{$infra->id}", ['name' => 'X'])->assertNotFound();
        $this->actingAs($this->admin)->postJson("/api/users/{$infra->id}/toggle-status")->assertNotFound();
        $this->actingAs($this->admin)->patchJson("/api/users/{$this->resident->id}", ['role' => 'SUPERADMIN'])->assertUnprocessable();
        $this->actingAs($this->admin)->getJson('/api/users/' . $infra->id . '/access')->assertForbidden();
    }

    public function test_admin_user_list_only_shows_residentes(): void
    {
        $roles = collect($this->actingAs($this->admin)->getJson('/api/users')->assertOk()->json('data'))->pluck('role')->unique()->all();

        $this->assertSame(['RESIDENTE'], $roles);
    }

    // ── S7 + D17: bloqueo y Traspaso ──────────────────────────────────────────

    public function test_resident_with_active_localization_cannot_be_deactivated_or_change_role(): void
    {
        $this->localization();

        $this->actingAs($this->admin)->postJson("/api/users/{$this->resident->id}/toggle-status")
            ->assertUnprocessable()
            ->assertJsonPath('errors.user.0', fn ($m) => str_contains($m, 'Tienda Sur') && str_contains($m, 'Traspase'));
        $this->actingAs($this->superadmin)->patchJson("/api/users/{$this->resident->id}", ['status' => 'Inactive'])->assertUnprocessable();
        $this->actingAs($this->superadmin)->patchJson("/api/users/{$this->resident->id}", ['role' => 'INFRAESTRUCTURA'])->assertUnprocessable();
        $this->assertSame('Active', $this->resident->fresh()->status);
    }

    public function test_resident_with_open_custom_project_is_blocked_but_closed_ones_are_not(): void
    {
        Project::factory()->create(['id' => 'PRJ-904', 'resident_user_id' => $this->resident->id, 'status' => 'EN_EJECUCION']);

        $this->actingAs($this->admin)->postJson("/api/users/{$this->resident->id}/toggle-status")
            ->assertUnprocessable()
            ->assertJsonPath('errors.user.0', fn ($m) => str_contains($m, 'PRJ-904'));

        Project::whereKey('PRJ-904')->update(['status' => 'COMPLETADO_PAGADO']);
        $this->actingAs($this->admin)->postJson("/api/users/{$this->resident->id}/toggle-status")->assertOk();
    }

    public function test_transfer_moves_localizations_and_open_custom_projects_with_audit(): void
    {
        $loc = $this->localization();
        $inactive = $this->localization(['title' => 'Vieja', 'is_active' => false]);
        $registered = Project::factory()->create(['id' => 'PRJ-905', 'localization_id' => $loc->id, 'status' => 'EN_EJECUCION']);
        $custom = Project::factory()->create(['id' => 'PRJ-906', 'resident_user_id' => $this->resident->id, 'status' => 'INFORME_ENVIADO']);
        $closed = Project::factory()->create(['id' => 'PRJ-907', 'resident_user_id' => $this->resident->id, 'status' => 'COMPLETADO_PAGADO']);

        $this->actingAs($this->admin)->postJson("/api/residents/{$this->resident->id}/transfer", ['toUserId' => $this->other->id, 'reason' => 'Renuncia'])
            ->assertOk()->assertJson(['localizations' => 2, 'projects' => 1]);

        $this->assertSame($this->other->id, $loc->fresh()->resident_user_id);
        $this->assertSame($this->other->id, $inactive->fresh()->resident_user_id);
        $this->assertSame($this->other->id, $custom->fresh()->resident_user_id);
        $this->assertSame($this->resident->id, $closed->fresh()->resident_user_id);
        $this->assertDatabaseHas('audit_logs', ['project_id' => 'PRJ-906', 'action' => 'Cambio de residente de obra', 'observations' => 'Renuncia']);
        $this->assertSame(2, \App\Models\ConfigAuditLog::where('action', 'Cambio de residente de ubicacion')->count());

        // Ya sin pendientes puede desactivarse.
        $this->actingAs($this->admin)->postJson("/api/users/{$this->resident->id}/toggle-status")->assertOk();
    }

    public function test_transfer_validates_destination_and_reason_and_role(): void
    {
        $inactive = User::factory()->create(['role' => 'RESIDENTE', 'status' => 'Inactive']);
        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $url = "/api/residents/{$this->resident->id}/transfer";

        $this->actingAs($this->admin)->postJson($url, ['toUserId' => $this->other->id])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson($url, ['toUserId' => $inactive->id, 'reason' => 'x y z'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson($url, ['toUserId' => $infra->id, 'reason' => 'x y z'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson($url, ['toUserId' => $this->resident->id, 'reason' => 'x y z'])->assertUnprocessable();
        $this->actingAs($this->admin)->postJson("/api/residents/{$infra->id}/transfer", ['toUserId' => $this->other->id, 'reason' => 'x y z'])->assertUnprocessable();
    }

    public function test_transfer_is_forbidden_for_other_roles(): void
    {
        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);

        $this->actingAs($infra)->postJson("/api/residents/{$this->resident->id}/transfer", ['toUserId' => $this->other->id, 'reason' => 'abc'])->assertForbidden();
    }
}
