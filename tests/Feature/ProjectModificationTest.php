<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectMaterial;
use App\Models\ProjectModificationRequest;
use App\Models\ProjectProposal;
use App\Models\User;
use App\Services\ProjectModificationService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ProjectModificationTest extends TestCase
{
    use RefreshDatabase;

    private User $infra;
    private User $otherInfra;
    private User $auditoria;
    private User $procura;
    private Project $project;
    private ProjectMaterial $outlets;
    private ProjectMaterial $points;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->otherInfra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->auditoria = User::factory()->create(['role' => 'AUDITORIA']);
        $this->procura = User::factory()->create(['role' => 'PROCURA']);

        $contractor = Contractor::factory()->create();
        $this->project = Project::factory()->create([
            'status' => 'EN_EJECUCION',
            'selected_contractor_code' => $contractor->code,
            'requested_by_user_id' => $this->infra->id,
        ]);
        $this->outlets = ProjectMaterial::factory()->create(['project_id' => $this->project->id, 'name' => 'Tomacorriente', 'quantity' => 12, 'unit' => 'und']);
        $this->points = ProjectMaterial::factory()->create(['project_id' => $this->project->id, 'name' => 'Punto', 'quantity' => 7, 'unit' => 'und']);

        $proposal = ProjectProposal::factory()->create([
            'project_id' => $this->project->id,
            'contractor_code' => $contractor->code,
            'total_cost' => 10000,
            'material_items' => [
                ['materialName' => 'Tomacorriente', 'unit_price_usd' => 50],
                ['materialName' => 'Punto', 'unit_price_usd' => 30],
            ],
        ]);
        $this->project->update(['selected_proposal_id' => $proposal->id]);
    }

    private function payload(array $items = null, string $reason = 'Cambio de arquitectura'): array
    {
        return [
            'reason' => $reason,
            'items' => $items ?? [['materialId' => $this->outlets->id, 'type' => 'AUMENTO', 'quantity' => 8]],
        ];
    }

    private function create(?User $user = null, ?array $items = null): ProjectModificationRequest
    {
        $this->actingAs($user ?? $this->infra);
        $id = $this->postJson("/api/projects/{$this->project->id}/modifications", $this->payload($items))->assertCreated()->json('data.id');

        return ProjectModificationRequest::findOrFail($id);
    }

    private function approve(ProjectModificationRequest $request): void
    {
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/modifications/{$request->id}/approval")->assertOk();
    }

    public function test_owner_creates_increase_at_approved_unit_cost(): void
    {
        $request = $this->create();

        $this->assertEquals('PENDIENTE', $request->status);
        $line = $request->items->first();
        $this->assertEquals(50, $line->unit_price_usd);
        $this->assertEquals(400, $request->netAmountUsd());
        $this->assertDatabaseHas('audit_logs', ['project_id' => $this->project->id, 'action' => 'Solicitud de modificacion de obra']);
        $this->assertEquals('EN_EJECUCION', $this->project->fresh()->status);
    }

    public function test_one_request_can_mix_increase_and_decrease(): void
    {
        $request = $this->create(null, [
            ['materialId' => $this->outlets->id, 'type' => 'AUMENTO', 'quantity' => 8],
            ['materialId' => $this->points->id, 'type' => 'DISMINUCION', 'quantity' => 4],
        ]);

        $this->assertEquals(400 - 120, $request->netAmountUsd());
        $this->approve($request);

        $effective = app(ProjectModificationService::class)->effectiveQuantities($this->project->fresh('materials'));
        $this->assertEquals(20, $effective[$this->outlets->id]['final']);
        $this->assertEquals(3, $effective[$this->points->id]['final']);
        $this->assertEquals(12, $effective[$this->outlets->id]['contracted']);
    }

    public function test_decrease_cannot_exceed_current_quantity_and_accumulates(): void
    {
        $this->approve($this->create(null, [['materialId' => $this->points->id, 'type' => 'DISMINUCION', 'quantity' => 5]]));

        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/modifications", $this->payload([
            ['materialId' => $this->points->id, 'type' => 'DISMINUCION', 'quantity' => 3],
        ]))->assertStatus(422);

        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/modifications", $this->payload([
            ['materialId' => $this->points->id, 'type' => 'DISMINUCION', 'quantity' => 2],
        ]))->assertCreated();
    }

    public function test_concurrent_pending_decreases_are_revalidated_on_approval(): void
    {
        $first = $this->create(null, [['materialId' => $this->points->id, 'type' => 'DISMINUCION', 'quantity' => 5]]);
        $second = $this->create(null, [['materialId' => $this->points->id, 'type' => 'DISMINUCION', 'quantity' => 5]]);

        $this->approve($first);
        $this->actingAs($this->auditoria)
            ->postJson("/api/projects/{$this->project->id}/modifications/{$second->id}/approval")
            ->assertStatus(422);
    }

    public function test_rejects_repeated_or_foreign_items_and_zero_quantity(): void
    {
        $foreign = ProjectMaterial::factory()->create(['project_id' => Project::factory()->create()->id, 'name' => 'Otro', 'quantity' => 5]);

        $this->actingAs($this->infra);
        $url = "/api/projects/{$this->project->id}/modifications";
        $this->postJson($url, $this->payload([['materialId' => $foreign->id, 'type' => 'AUMENTO', 'quantity' => 1]]))->assertStatus(422);
        $this->postJson($url, $this->payload([
            ['materialId' => $this->outlets->id, 'type' => 'AUMENTO', 'quantity' => 1],
            ['materialId' => $this->outlets->id, 'type' => 'DISMINUCION', 'quantity' => 1],
        ]))->assertStatus(422);
        $this->postJson($url, $this->payload([['materialId' => $this->outlets->id, 'type' => 'AUMENTO', 'quantity' => 0]]))->assertStatus(422);
    }

    public function test_only_in_execution(): void
    {
        $this->project->update(['status' => 'INFORME_ENVIADO']);

        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/modifications", $this->payload())->assertStatus(422);
    }

    public function test_only_the_owner_of_the_project_can_request(): void
    {
        $this->actingAs($this->otherInfra)->postJson("/api/projects/{$this->project->id}/modifications", $this->payload())->assertNotFound();
        $this->actingAs($this->procura)->postJson("/api/projects/{$this->project->id}/modifications", $this->payload())->assertForbidden();
    }

    public function test_only_reviewers_can_approve_or_reject(): void
    {
        $request = $this->create();

        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/modifications/{$request->id}/approval")->assertForbidden();
        $this->actingAs($this->procura)->postJson("/api/projects/{$this->project->id}/modifications/{$request->id}/rejection", ['reason' => 'x'])->assertForbidden();
    }

    public function test_rejection_requires_reason_and_owner_can_edit_and_resubmit(): void
    {
        $request = $this->create();
        $url = "/api/projects/{$this->project->id}/modifications/{$request->id}";

        $this->actingAs($this->auditoria)->postJson("{$url}/rejection", [])->assertStatus(422);
        $this->actingAs($this->auditoria)->postJson("{$url}/rejection", ['reason' => 'Falta sustento'])->assertOk()->assertJsonPath('data.status', 'RECHAZADA');

        $this->actingAs($this->infra)->putJson($url, $this->payload([['materialId' => $this->outlets->id, 'type' => 'AUMENTO', 'quantity' => 6]], 'Con sustento'))
            ->assertOk()
            ->assertJsonPath('data.status', 'PENDIENTE')
            ->assertJsonPath('data.rejectionReason', null);
        $this->assertEquals(6, $request->fresh()->items->first()->quantity);
    }

    public function test_approved_request_cannot_be_edited_or_decided_again(): void
    {
        $request = $this->create();
        $this->approve($request);
        $url = "/api/projects/{$this->project->id}/modifications/{$request->id}";

        $this->actingAs($this->infra)->putJson($url, $this->payload())->assertStatus(422);
        $this->actingAs($this->auditoria)->postJson("{$url}/rejection", ['reason' => 'x'])->assertStatus(422);
        $this->actingAs($this->auditoria)->postJson("{$url}/approval")->assertStatus(422);
    }

    public function test_request_of_another_project_is_not_reachable_through_this_one(): void
    {
        $request = $this->create();
        $other = Project::factory()->create(['status' => 'EN_EJECUCION']);

        $this->actingAs($this->auditoria)->postJson("/api/projects/{$other->id}/modifications/{$request->id}/approval")->assertNotFound();
    }

    public function test_responsible_roles_are_configurable(): void
    {
        DB::table('app_settings')->where('key', 'modificaciones_roles_aprobadores')->update(['value' => json_encode(['PROCURA'])]);
        DB::table('app_settings')->where('key', 'modificaciones_roles_solicitantes')->update(['value' => json_encode(['INFRAESTRUCTURA', 'PROCURA'])]);
        SettingsService::forget();

        $request = $this->create();
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/modifications/{$request->id}/approval")->assertForbidden();
        $this->actingAs($this->procura)->postJson("/api/projects/{$this->project->id}/modifications/{$request->id}/approval")->assertOk();
    }

    public function test_listing_exposes_effective_quantities_and_permissions(): void
    {
        $this->approve($this->create());
        $this->create();

        $this->actingAs($this->infra)->getJson("/api/projects/{$this->project->id}/modifications")
            ->assertOk()
            ->assertJsonPath('hasPending', true)
            ->assertJsonPath('canRequest', true)
            ->assertJsonPath('canReview', false)
            ->assertJsonPath("effectiveQuantities.{$this->outlets->id}.final", 20)
            ->assertJsonCount(2, 'data');
    }

    public function test_inbox_lists_only_visible_projects_and_hides_it_from_other_roles(): void
    {
        $this->create();
        $foreign = Project::factory()->create(['status' => 'EN_EJECUCION', 'requested_by_user_id' => $this->otherInfra->id]);
        ProjectModificationRequest::create(['project_id' => $foreign->id, 'reason' => 'x', 'status' => 'PENDIENTE']);

        $this->actingAs($this->infra)->getJson('/api/modification-requests')->assertOk()->assertJsonCount(1, 'data');
        $this->actingAs($this->auditoria)->getJson('/api/modification-requests?status=PENDIENTE')->assertOk()->assertJsonCount(2, 'data');
        $this->actingAs(User::factory()->create(['role' => 'FINANZAS']))->getJson('/api/modification-requests')->assertForbidden();
    }

    public function test_approval_adjusts_awarded_total_but_not_the_approved_ceiling(): void
    {
        $this->project->update(['approved_investment_amount' => 20000]);

        $this->approve($this->create(null, [
            ['materialId' => $this->outlets->id, 'type' => 'AUMENTO', 'quantity' => 8],
            ['materialId' => $this->points->id, 'type' => 'DISMINUCION', 'quantity' => 4],
        ]));

        $proposal = ProjectProposal::find($this->project->fresh()->selected_proposal_id);
        $this->assertEquals(10000 + 400 - 120, $proposal->total_cost);
        $this->assertEquals(20000, $this->project->fresh()->approved_investment_amount);
    }

    public function test_pending_or_rejected_requests_do_not_touch_the_budget(): void
    {
        $request = $this->create();
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/modifications/{$request->id}/rejection", ['reason' => 'No'])->assertOk();

        $this->assertEquals(10000, ProjectProposal::find($this->project->fresh()->selected_proposal_id)->total_cost);
    }

    public function test_alerts_presidencia_when_awarded_exceeds_the_ceiling(): void
    {
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);
        $this->project->update(['approved_investment_amount' => 10200]);

        $this->approve($this->create());

        $this->assertDatabaseHas('app_notifications', ['user_id' => $presidencia->id]);
    }

    public function test_no_alert_when_awarded_stays_within_the_ceiling(): void
    {
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);
        $this->project->update(['approved_investment_amount' => 20000]);

        $this->approve($this->create());

        $this->assertDatabaseMissing('app_notifications', ['user_id' => $presidencia->id]);
    }

    public function test_history_detail_lists_modifications_and_timeline_events(): void
    {
        $this->approve($this->create());

        $detail = app(\App\Services\ProjectHistoryDetailBuilder::class)->build($this->project->fresh());

        $this->assertCount(1, $detail['modifications']);
        $this->assertSame('APROBADA', $detail['modifications'][0]['status']);
        $this->assertEquals(400, $detail['modifications'][0]['netAmountUsd']);
        $this->assertContains('Aprobacion de modificacion de obra', array_column($detail['timeline'], 'action'));
    }

    public function test_actions_notify_configured_roles(): void
    {
        $this->assertDatabaseHas('notification_rules', ['action' => 'Solicitud de modificacion de obra', 'role' => 'AUDITORIA', 'channel' => 'app']);
        $this->assertDatabaseHas('notification_rules', ['action' => 'Aprobacion de modificacion de obra', 'role' => 'RESIDENTE_ASIGNADO', 'channel' => 'app']);
        $this->assertDatabaseHas('notification_rules', ['action' => 'Rechazo de modificacion de obra', 'role' => 'SOLICITANTE', 'channel' => 'app']);
    }
}
