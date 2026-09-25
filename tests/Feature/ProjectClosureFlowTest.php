<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectClosureReport;
use App\Models\ProjectMaterial;
use App\Models\ProjectPayment;
use App\Models\ProjectProposal;
use App\Models\User;
use App\Services\ClosureReportLinkService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProjectClosureFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $infra;
    private User $otherInfra;
    private User $auditoria;
    private User $procura;
    private Project $project;
    private ProjectClosureReport $report;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Notification::fake();

        $this->infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->otherInfra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->auditoria = User::factory()->create(['role' => 'AUDITORIA']);
        $this->procura = User::factory()->create(['role' => 'PROCURA']);

        $contractor = Contractor::factory()->create(['email' => 'contratista@example.com']);
        $this->project = Project::factory()->create([
            'status' => 'EN_EJECUCION',
            'selected_contractor_code' => $contractor->code,
            'requested_by_user_id' => $this->infra->id,
        ]);
        ProjectMaterial::factory()->create(['project_id' => $this->project->id, 'name' => 'Tomacorriente', 'quantity' => 12, 'unit' => 'und']);
        ProjectMaterial::factory()->create(['project_id' => $this->project->id, 'name' => 'Cable', 'quantity' => 100, 'unit' => 'm']);

        $proposal = ProjectProposal::factory()->create([
            'project_id' => $this->project->id,
            'contractor_code' => $contractor->code,
            'total_cost' => 10000,
            'material_items' => [
                ['materialName' => 'Tomacorriente', 'unit_price_usd' => 50],
                ['materialName' => 'Cable', 'unit_price_usd' => 2],
            ],
        ]);
        $this->project->update(['selected_proposal_id' => $proposal->id]);
        ProjectPayment::create(['project_id' => $this->project->id, 'proposal_id' => $proposal->id, 'payment_type' => 'ADVANCE', 'amount' => 3000, 'paid_date' => now()->toDateString()]);

        $this->report = app(ClosureReportLinkService::class)->open($this->project->refresh());
    }

    private function photo(string $name = 'foto.jpg'): UploadedFile
    {
        return UploadedFile::fake()->image($name);
    }

    private function fullItems(?callable $override = null): array
    {
        return $this->report->items->map(function ($item) use ($override) {
            $row = ['id' => $item->id, 'executedQuantity' => $item->contracted_quantity];

            return $override ? $override($item, $row) : $row;
        })->all();
    }

    private function residentPayload(?callable $override = null): array
    {
        $items = $this->report->fresh()->items->map(function ($item) use ($override) {
            $row = ['id' => $item->id, 'residentQuantity' => $item->executed_quantity];

            return $override ? $override($item, $row) : $row;
        })->all();

        return ['notes' => 'Corroborado', 'items' => $items];
    }

    private function submitReport(): void
    {
        $this->post("/api/public/closures/{$this->report->id}/photos", ['image' => $this->photo()])->assertStatus(201);
        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $this->fullItems()])->assertOk();
    }

    public function test_open_creates_report_with_contracted_items_and_sends_link(): void
    {
        $this->assertEquals('ABIERTO', $this->report->status);
        $this->assertCount(2, $this->report->items);
        $this->assertEquals(50, $this->report->items->firstWhere('name', 'Tomacorriente')->unit_price_usd);
        Notification::assertSentOnDemand(\App\Notifications\SupplierClosureReportLink::class);
    }

    public function test_public_link_shows_report_without_internal_data(): void
    {
        $response = $this->getJson("/api/public/closures/{$this->report->id}")->assertOk();

        $response->assertJsonPath('data.status', 'ABIERTO')->assertJsonPath('data.editable', true);
        $response->assertJsonMissingPath('data.auditNotes');
        $response->assertJsonPath('data.items.0.unitPriceUsd', null);
        $this->getJson('/api/public/closures/no-existe')->assertStatus(404);
    }

    public function test_submit_requires_at_least_one_photo(): void
    {
        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $this->fullItems()])->assertStatus(422);
        $this->assertEquals('EN_EJECUCION', $this->project->fresh()->status);
    }

    public function test_decrease_requires_a_note_and_excess_is_rejected(): void
    {
        $this->post("/api/public/closures/{$this->report->id}/photos", ['image' => $this->photo()])->assertStatus(201);

        $decrease = $this->fullItems(fn ($item, $row) => $item->name === 'Tomacorriente' ? [...$row, 'executedQuantity' => 8] : $row);
        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $decrease])->assertStatus(422);

        $excess = $this->fullItems(fn ($item, $row) => $item->name === 'Cable' ? [...$row, 'executedQuantity' => 120] : $row);
        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $excess])->assertStatus(422);

        $ok = $this->fullItems(fn ($item, $row) => $item->name === 'Tomacorriente' ? [...$row, 'executedQuantity' => 8, 'note' => 'Solo se requirieron 8'] : $row);
        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $ok])->assertOk();
        $this->assertEquals('INFORME_ENVIADO', $this->project->fresh()->status);
    }

    public function test_report_is_locked_after_submit(): void
    {
        $this->submitReport();

        $this->post("/api/public/closures/{$this->report->id}/photos", ['image' => $this->photo()])->assertStatus(422);
        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $this->fullItems()])->assertStatus(422);
    }

    public function test_steps_cannot_be_skipped(): void
    {
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/audit-approval")->assertStatus(422);
        $this->actingAs($this->procura)->postJson("/api/projects/{$this->project->id}/closure-report/finiquito-request")->assertStatus(422);

        $this->submitReport();

        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/audit-approval")->assertStatus(422);
        $this->actingAs($this->procura)->postJson("/api/projects/{$this->project->id}/closure-report/finiquito-request")->assertStatus(422);
    }

    public function test_resident_needs_a_verification_photo(): void
    {
        $this->submitReport();

        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/closure-report/resident-approval", $this->residentPayload())->assertStatus(422);
    }

    public function test_only_assigned_resident_can_verify(): void
    {
        $this->project->update(['resident_user_id' => $this->infra->id]);
        $this->submitReport();

        $this->actingAs($this->otherInfra)->post("/api/projects/{$this->project->id}/closure-report/photos", ['image' => $this->photo()])->assertStatus(404);
        $this->actingAs($this->otherInfra)->postJson("/api/projects/{$this->project->id}/closure-report/resident-approval", $this->residentPayload())->assertStatus(404);

        $this->actingAs($this->infra)->post("/api/projects/{$this->project->id}/closure-report/photos", ['image' => $this->photo()])->assertStatus(201);
        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/closure-report/resident-approval", $this->residentPayload())
            ->assertJsonPath('data.status', 'VERIFICANDO_FINALIZACION');
    }

    public function test_infra_user_who_does_not_own_the_project_cannot_reach_its_closure(): void
    {
        $this->submitReport();

        $this->actingAs($this->otherInfra)->getJson("/api/projects/{$this->project->id}/closure-report")->assertStatus(404);
        $this->actingAs($this->otherInfra)->post("/api/projects/{$this->project->id}/closure-report/photos", ['image' => $this->photo()])->assertStatus(404);
        $this->actingAs($this->otherInfra)->postJson("/api/projects/{$this->project->id}/closure-report/resident-approval", $this->residentPayload())->assertStatus(404);
    }

    public function test_resident_rejection_returns_to_execution_and_reopens_report(): void
    {
        $this->submitReport();

        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/closure-report/rejection")->assertStatus(422);
        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/closure-report/rejection", ['reason' => 'Faltan tomacorrientes'])
            ->assertJsonPath('data.status', 'EN_EJECUCION');

        $report = $this->report->fresh();
        $this->assertEquals('RECHAZADO', $report->status);
        $this->assertEquals(2, $report->revision);
        $this->assertEquals('Faltan tomacorrientes', $report->rejection_reason);
        $this->assertTrue($report->isEditableByContractor());

        $this->getJson("/api/public/closures/{$this->report->id}")->assertJsonPath('data.editable', true)->assertJsonPath('data.rejectionReason', 'Faltan tomacorrientes');
        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $this->fullItems()])->assertOk();
        $this->assertEquals('INFORME_ENVIADO', $this->project->fresh()->status);
    }

    public function test_audit_rejection_and_procura_return_flow(): void
    {
        $this->submitReport();
        $this->actingAs($this->infra)->post("/api/projects/{$this->project->id}/closure-report/photos", ['image' => $this->photo()]);
        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/closure-report/resident-approval", $this->residentPayload());

        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/rejection", ['reason' => 'Materiales no coinciden'])
            ->assertJsonPath('data.status', 'EN_EJECUCION');
        $this->assertEquals('AUDITORIA', $this->report->fresh()->rejected_by_role);

        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $this->fullItems()])->assertOk();
        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/closure-report/resident-approval", $this->residentPayload())->assertOk();
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/audit-approval")
            ->assertJsonPath('data.status', 'PENDIENTE_SOLICITUD_FINIQUITO');

        $this->actingAs($this->procura)->postJson("/api/projects/{$this->project->id}/closure-report/finiquito-return", ['reason' => 'Revisar cantidades'])
            ->assertJsonPath('data.status', 'VERIFICANDO_FINALIZACION');
    }

    public function test_finiquito_amount_is_contracted_minus_advance_minus_reductions(): void
    {
        $this->post("/api/public/closures/{$this->report->id}/photos", ['image' => $this->photo()]);
        $items = $this->fullItems(fn ($item, $row) => $item->name === 'Tomacorriente' ? [...$row, 'executedQuantity' => 8, 'note' => 'Se redujo'] : $row);
        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $items])->assertOk();
        $this->actingAs($this->infra)->post("/api/projects/{$this->project->id}/closure-report/photos", ['image' => $this->photo()]);
        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/closure-report/resident-approval", $this->residentPayload());
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/audit-approval")->assertOk();

        // 10000 contratado − 3000 anticipo − (12−8)×50 disminución = 6800
        $this->assertEquals(6800.00, $this->report->fresh()->finiquito_amount);
        $this->assertTrue((bool) $this->project->fresh()->quality_verified);
    }

    public function test_assign_resident_only_accepts_infrastructure_users(): void
    {
        $this->actingAs($this->infra)->patchJson("/api/projects/{$this->project->id}/resident", ['residentUserId' => $this->auditoria->id])->assertStatus(422);
        $this->actingAs($this->infra)->patchJson("/api/projects/{$this->project->id}/resident", ['residentUserId' => $this->infra->id])->assertOk();
        $this->assertEquals($this->infra->id, $this->project->fresh()->resident_user_id);
    }

    public function test_final_payment_requires_procura_request(): void
    {
        $finanzas = User::factory()->create(['role' => 'FINANZAS']);

        $this->actingAs($finanzas)->postJson("/api/projects/{$this->project->id}/payments", ['paymentType' => 'FINAL', 'amount' => 100])->assertStatus(422);
    }

    private function toResidentStage(): void
    {
        $this->submitReport();
        $this->actingAs($this->infra)->post("/api/projects/{$this->project->id}/closure-report/photos", ['image' => $this->photo()]);
    }

    public function test_resident_must_measure_every_item_within_contracted(): void
    {
        $this->toResidentStage();
        $url = "/api/projects/{$this->project->id}/closure-report/resident-approval";

        $this->actingAs($this->infra)->postJson($url, ['items' => [['id' => $this->report->items->first()->id, 'residentQuantity' => 1]]])->assertStatus(422);
        $this->actingAs($this->infra)->postJson($url, $this->residentPayload(fn ($i, $row) => $i->name === 'Cable' ? [...$row, 'residentQuantity' => 150, 'note' => 'x'] : $row))->assertStatus(422);
        $this->assertEquals('INFORME_ENVIADO', $this->project->fresh()->status);
    }

    public function test_resident_difference_from_contractor_requires_a_note(): void
    {
        $this->toResidentStage();
        $url = "/api/projects/{$this->project->id}/closure-report/resident-approval";

        $this->actingAs($this->infra)->postJson($url, $this->residentPayload(fn ($i, $row) => $i->name === 'Cable' ? [...$row, 'residentQuantity' => 90] : $row))->assertStatus(422);

        $this->actingAs($this->infra)->postJson($url, $this->residentPayload(fn ($i, $row) => $i->name === 'Cable' ? [...$row, 'residentQuantity' => 90, 'note' => 'Faltan 10 m'] : $row))->assertOk();
        $cable = $this->report->fresh()->items->firstWhere('name', 'Cable');
        $this->assertEquals(100, $cable->executed_quantity);
        $this->assertEquals(90, $cable->resident_quantity);
    }

    public function test_audit_defaults_to_resident_measurement_and_can_override_with_note(): void
    {
        $this->toResidentStage();
        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/closure-report/resident-approval", $this->residentPayload(fn ($i, $row) => $i->name === 'Cable' ? [...$row, 'residentQuantity' => 90, 'note' => 'Faltan 10 m'] : $row))->assertOk();

        $audit = "/api/projects/{$this->project->id}/closure-report/audit-approval";
        $cable = $this->report->fresh()->items->firstWhere('name', 'Cable');

        $this->actingAs($this->auditoria)->postJson($audit, ['items' => [['id' => $cable->id, 'auditQuantity' => 95]]])->assertStatus(422);
        $this->assertEquals('VERIFICANDO_FINALIZACION', $this->project->fresh()->status);

        $this->actingAs($this->auditoria)->postJson($audit, ['items' => [['id' => $cable->id, 'auditQuantity' => 95, 'note' => 'Se comprobó 95 m']]])->assertOk();
        $this->assertEquals(95, $cable->fresh()->audit_quantity);
        // 10000 − 3000 anticipo − (100−95)×2 = 6990
        $this->assertEquals(6990.00, $this->report->fresh()->finiquito_amount);
    }

    public function test_audit_without_adjustments_uses_resident_quantity(): void
    {
        $this->toResidentStage();
        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/closure-report/resident-approval", $this->residentPayload(fn ($i, $row) => $i->name === 'Cable' ? [...$row, 'residentQuantity' => 90, 'note' => 'Faltan 10 m'] : $row))->assertOk();

        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/audit-approval")->assertOk();

        // 10000 − 3000 − (100−90)×2 = 6980 sobre la medición del residente, no la del contratista
        $this->assertEquals(6980.00, $this->report->fresh()->finiquito_amount);
    }

    public function test_rejection_clears_resident_and_audit_measurements(): void
    {
        $this->toResidentStage();
        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/closure-report/resident-approval", $this->residentPayload())->assertOk();
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/rejection", ['reason' => 'Revisar'])->assertOk();

        foreach ($this->report->fresh()->items as $item) {
            $this->assertNull($item->resident_quantity);
            $this->assertNull($item->audit_quantity);
        }
    }

    public function test_public_link_never_exposes_resident_or_audit_measurements(): void
    {
        $this->getJson("/api/public/closures/{$this->report->id}")->assertJsonMissingPath('data.items.0.residentQuantity')->assertJsonMissingPath('data.items.0.auditQuantity');
    }
}
