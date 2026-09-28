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
    private User $resident;
    private User $otherResident;
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
        $this->resident = User::factory()->create(['role' => 'RESIDENTE']);
        $this->otherResident = User::factory()->create(['role' => 'RESIDENTE']);
        $this->auditoria = User::factory()->create(['role' => 'AUDITORIA']);
        $this->procura = User::factory()->create(['role' => 'PROCURA']);

        $contractor = Contractor::factory()->create(['email' => 'contratista@example.com']);
        $this->project = Project::factory()->create([
            'status' => 'EN_EJECUCION',
            'selected_contractor_code' => $contractor->code,
            'requested_by_user_id' => $this->infra->id,
            'resident_user_id' => $this->resident->id,
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
            if ((float) $item->executed_quantity !== (float) $item->contracted_quantity) {
                $row['note'] = 'Diferencia verificada en obra';
            }

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

    public function test_pending_modification_blocks_contractor_submission(): void
    {
        $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/modifications", [
            'reason' => 'Más tomacorrientes',
            'items' => [['materialId' => $this->project->materials->firstWhere('name', 'Tomacorriente')->id, 'type' => 'AUMENTO', 'quantity' => 8]],
        ])->assertCreated();

        $this->post("/api/public/closures/{$this->report->id}/photos", ['image' => $this->photo()])->assertStatus(201);
        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $this->fullItems()])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Hay modificaciones de obra pendientes de aprobación; deben resolverse antes de enviar el informe de cierre.']);
    }

    public function test_approved_modification_preloads_closure_format_and_drives_final_quantities(): void
    {
        $outlets = $this->project->materials->firstWhere('name', 'Tomacorriente');
        $cable = $this->project->materials->firstWhere('name', 'Cable');
        $id = $this->actingAs($this->infra)->postJson("/api/projects/{$this->project->id}/modifications", [
            'reason' => 'Cambio de arquitectura',
            'items' => [
                ['materialId' => $outlets->id, 'type' => 'AUMENTO', 'quantity' => 8],
                ['materialId' => $cable->id, 'type' => 'DISMINUCION', 'quantity' => 40],
            ],
        ])->assertCreated()->json('data.id');
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/modifications/{$id}/approval")->assertOk();

        $items = $this->report->fresh()->items;
        $outletItem = $items->firstWhere('name', 'Tomacorriente');
        $this->assertEquals(20, $outletItem->contracted_quantity);
        $this->assertEquals(12, $outletItem->original_quantity);
        $this->assertEquals(20, $outletItem->executed_quantity);
        $this->assertEquals(60, $items->firstWhere('name', 'Cable')->contracted_quantity);

        $this->getJson("/api/public/closures/{$this->report->id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Tomacorriente', 'contractedQuantity' => 20, 'originalQuantity' => 12, 'modificationQuantity' => 8]);

        $this->post("/api/public/closures/{$this->report->id}/photos", ['image' => $this->photo()])->assertStatus(201);
        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $this->fullItems()])->assertOk();
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

        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload())->assertStatus(422);
    }

    public function test_only_effective_resident_can_verify(): void
    {
        $this->submitReport();

        $this->actingAs($this->otherResident)->post("/api/resident/projects/{$this->project->id}/photos", ['image' => $this->photo()])->assertStatus(404);
        $this->actingAs($this->otherResident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload())->assertStatus(404);

        $this->actingAs($this->resident)->post("/api/resident/projects/{$this->project->id}/photos", ['image' => $this->photo()])->assertStatus(201);
        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload())
            ->assertJsonPath('data.status', 'VERIFICANDO_FINALIZACION');
    }

    public function test_creator_cannot_act_as_resident_nor_assign_one(): void
    {
        $this->submitReport();
        $id = $this->project->id;

        $this->actingAs($this->infra)->post("/api/resident/projects/{$id}/photos", ['image' => $this->photo()])->assertForbidden();
        $this->actingAs($this->infra)->postJson("/api/resident/projects/{$id}/approval", $this->residentPayload())->assertForbidden();
        $this->actingAs($this->infra)->post("/api/projects/{$id}/closure-report/photos", ['image' => $this->photo()])->assertForbidden();
        $this->actingAs($this->infra)->postJson("/api/projects/{$id}/closure-report/resident-approval", $this->residentPayload())->assertForbidden();
        $this->actingAs($this->infra)->postJson("/api/projects/{$id}/closure-report/rejection", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->infra)->patchJson("/api/projects/{$id}/resident", ['residentUserId' => $this->otherResident->id, 'reason' => 'x'])->assertForbidden();
        $this->assertEquals('INFORME_ENVIADO', $this->project->fresh()->status);

        // Solo lectura de su propio cierre
        $this->actingAs($this->infra)->getJson("/api/projects/{$id}/closure-report")->assertOk();
    }

    public function test_infra_user_who_does_not_own_the_project_cannot_reach_its_closure(): void
    {
        $this->submitReport();

        $this->actingAs($this->otherInfra)->getJson("/api/projects/{$this->project->id}/closure-report")->assertStatus(404);
        $this->actingAs($this->otherInfra)->post("/api/projects/{$this->project->id}/closure-report/photos", ['image' => $this->photo()])->assertStatus(404);
        $this->actingAs($this->otherInfra)->postJson("/api/projects/{$this->project->id}/closure-report/resident-approval", $this->residentPayload())->assertStatus(404);
    }

    public function test_submit_requires_an_effective_resident(): void
    {
        $this->project->update(['resident_user_id' => null]);
        $this->post("/api/public/closures/{$this->report->id}/photos", ['image' => $this->photo()])->assertStatus(201);

        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $this->fullItems()])->assertStatus(422);
        $this->assertEquals('EN_EJECUCION', $this->project->fresh()->status);
    }

    public function test_previous_resident_loses_access_when_the_location_resident_changes(): void
    {
        $location = \App\Models\Localization::create([
            'title' => 'Tienda Sur', 'city' => 'Valencia', 'type' => 'TIENDA', 'is_active' => true, 'resident_user_id' => $this->resident->id,
        ]);
        $this->project->update(['localization_id' => $location->id, 'resident_user_id' => null]);
        $this->submitReport();
        $url = "/api/resident/projects/{$this->project->id}";

        $this->actingAs($this->resident)->getJson($url)->assertOk();
        $location->update(['resident_user_id' => $this->otherResident->id]);

        $this->actingAs($this->resident)->getJson($url)->assertNotFound();
        $this->actingAs($this->otherResident)->getJson($url)->assertOk();
    }

    public function test_resident_listing_only_includes_own_projects_with_a_report(): void
    {
        $this->submitReport();
        Project::factory()->create(['status' => 'EN_EJECUCION', 'resident_user_id' => $this->resident->id]); // sin informe

        $response = $this->actingAs($this->resident)->getJson('/api/resident/projects')->assertOk();
        $this->assertSame([$this->project->id], collect($response->json('data'))->pluck('id')->all());
        $this->assertTrue($response->json('data.0.pendingAction'));
        $this->assertCount(0, $this->actingAs($this->otherResident)->getJson('/api/resident/projects')->json('data'));
    }

    public function test_resident_resource_never_exposes_money_or_internal_notes(): void
    {
        $this->submitReport();
        $this->actingAs($this->resident)->post("/api/resident/projects/{$this->project->id}/photos", ['image' => $this->photo()])->assertStatus(201);

        $response = $this->actingAs($this->resident)->getJson("/api/resident/projects/{$this->project->id}")->assertOk();
        $json = json_encode($response->json());

        foreach (['unitPrice', 'unit_price', 'finiquito', 'auditNotes', 'auditQuantity', 'estimatedTotal', 'amount', 'executedQuantity', 'contractorNotes'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $json);
        }
        $response->assertJsonPath('data.closure.items.0.name', 'Tomacorriente');
        $this->assertStringStartsWith('resident/projects/', $response->json('data.closure.photos.0.path'));
    }

    public function test_resident_reads_only_technical_documents(): void
    {
        $docs = [];
        foreach (['PLANO', 'CALC', 'FOTO', 'COMPROBANTE_ANTICIPO', 'CORRECCION'] as $type) {
            $doc = \App\Models\ProjectDocument::create([
                'project_id' => $this->project->id, 'document_type' => $type, 'original_name' => "{$type}.pdf", 'stored_path' => "project-documents/x/{$type}.pdf",
                'mime_type' => 'application/pdf', 'size_bytes' => 1, 'uploaded_by' => $this->infra->id, 'version_number' => 1,
            ]);
            $doc->update(['document_group_id' => $doc->id]);
            Storage::disk('local')->put($doc->stored_path, 'x');
            $docs[$type] = $doc;
        }
        $base = "/api/resident/projects/{$this->project->id}/documents";

        $types = collect($this->actingAs($this->resident)->getJson($base)->assertOk()->json('data'))->pluck('documentType')->sort()->values()->all();
        $this->assertSame(['CALC', 'FOTO', 'PLANO'], $types);
        $this->actingAs($this->resident)->get("{$base}/{$docs['PLANO']->id}/download")->assertOk();
        $this->actingAs($this->resident)->get("{$base}/{$docs['FOTO']->id}/preview")->assertOk();
        $this->actingAs($this->resident)->get("{$base}/{$docs['COMPROBANTE_ANTICIPO']->id}/download")->assertNotFound();
        $this->actingAs($this->resident)->get("{$base}/{$docs['CORRECCION']->id}/preview")->assertNotFound();
        $this->actingAs($this->otherResident)->getJson($base)->assertNotFound();
    }

    public function test_resident_cannot_resend_and_auditor_cannot_measure(): void
    {
        $this->toResidentStage();
        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload())->assertOk();

        // Su informe ya está enviado: no puede reenviarlo ni rechazar (eso es de Auditoría)
        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload())->assertStatus(422);
        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/rejection", ['reason' => 'x'])->assertStatus(404);
        $this->assertEquals('VERIFICANDO_FINALIZACION', $this->project->fresh()->status);

        $this->actingAs($this->auditoria)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload())->assertForbidden();
    }

    public function test_only_admin_and_auditoria_can_resend_the_contractor_link(): void
    {
        $id = $this->project->id;
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($this->infra)->postJson("/api/projects/{$id}/closure-report/resend-link")->assertForbidden();
        $this->actingAs($this->resident)->postJson("/api/projects/{$id}/closure-report/resend-link")->assertForbidden();
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$id}/closure-report/resend-link")->assertOk();
        $this->actingAs($admin)->postJson("/api/projects/{$id}/closure-report/resend-link")->assertOk();
    }

    public function test_admin_can_act_as_resident(): void
    {
        $this->toResidentStage();
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($admin)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload())
            ->assertJsonPath('data.status', 'VERIFICANDO_FINALIZACION');
    }

    public function test_resident_reports_independently_before_the_contractor(): void
    {
        $url = "/api/resident/projects/{$this->project->id}";
        $this->assertTrue($this->actingAs($this->resident)->getJson($url)->json('data.pendingAction'));

        $this->actingAs($this->resident)->post("{$url}/photos", ['image' => $this->photo()])->assertStatus(201);
        $this->actingAs($this->resident)->postJson("{$url}/approval", $this->residentPayload(fn ($i, $row) => $i->name === 'Cable' ? [...$row, 'residentQuantity' => 90, 'note' => 'Faltan 10 m'] : $row))
            ->assertOk()->assertJsonPath('data.status', 'INFORME_ENVIADO')->assertJsonPath('data.pendingAction', false);
        $this->assertEquals('ABIERTO', $this->report->fresh()->status);

        // Auditoría aún no puede verificar: falta el informe del contratista
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/audit-approval")->assertStatus(422);

        $this->submitReport();
        $this->assertEquals('VERIFICANDO_FINALIZACION', $this->project->fresh()->status);
        $this->assertEquals('APROBADO_RESIDENTE', $this->report->fresh()->status);
    }

    public function test_resident_can_delete_own_draft_photos_only_before_sending(): void
    {
        $url = "/api/resident/projects/{$this->project->id}";
        $id = $this->actingAs($this->resident)->post("{$url}/photos", ['image' => $this->photo()])->assertStatus(201)->json('id');
        $this->post("/api/public/closures/{$this->report->id}/photos", ['image' => $this->photo()])->assertStatus(201);
        $contractorPhoto = $this->report->fresh()->photos()->where('uploaded_by_type', 'CONTRATISTA')->value('id');

        $this->actingAs($this->otherResident)->deleteJson("{$url}/photos/{$id}")->assertNotFound();
        $this->actingAs($this->resident)->deleteJson("{$url}/photos/{$contractorPhoto}")->assertNotFound();
        $this->actingAs($this->resident)->deleteJson("{$url}/photos/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('project_closure_photos', ['id' => $id]);

        $this->actingAs($this->resident)->post("{$url}/photos", ['image' => $this->photo()])->assertStatus(201);
        $this->actingAs($this->resident)->postJson("{$url}/approval", $this->residentPayload())->assertOk();
        $sent = $this->report->fresh()->photos()->where('uploaded_by_type', 'RESIDENTE')->value('id');
        $this->actingAs($this->resident)->deleteJson("{$url}/photos/{$sent}")->assertStatus(422);
    }

    public function test_contractor_link_never_shows_resident_photos(): void
    {
        $this->actingAs($this->resident)->post("/api/resident/projects/{$this->project->id}/photos", ['image' => $this->photo()])->assertStatus(201);
        $residentPhoto = $this->report->fresh()->photos()->where('uploaded_by_type', 'RESIDENTE')->value('id');
        $this->post("/api/public/closures/{$this->report->id}/photos", ['image' => $this->photo()])->assertStatus(201);

        $photos = $this->getJson("/api/public/closures/{$this->report->id}")->assertOk()->json('data.photos');
        $this->assertCount(1, $photos);
        $this->assertSame('CONTRATISTA', $photos[0]['uploadedByType']);
        $this->getJson("/api/public/closures/{$this->report->id}/photos/{$residentPhoto}")->assertNotFound();
    }

    public function test_resident_never_sees_what_the_contractor_declared(): void
    {
        $this->submitReport();

        $item = $this->actingAs($this->resident)->getJson("/api/resident/projects/{$this->project->id}")->assertOk()->json('data.closure.items.0');
        $this->assertArrayNotHasKey('executedQuantity', $item);
        $this->assertArrayNotHasKey('note', $item);
    }

    public function test_audit_rejection_and_procura_return_flow(): void
    {
        $this->submitReport();
        $this->actingAs($this->resident)->post("/api/resident/projects/{$this->project->id}/photos", ['image' => $this->photo()]);
        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload());

        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/rejection", ['reason' => 'Materiales no coinciden', 'target' => 'CONTRATISTA'])
            ->assertJsonPath('data.status', 'INFORME_ENVIADO');
        $this->assertEquals('AUDITORIA', $this->report->fresh()->rejected_by_role);
        $this->assertTrue($this->report->fresh()->isEditableByContractor());

        // Auditoría devolvió solo el informe del contratista: el del residente se conserva
        $this->assertNotNull($this->report->fresh()->resident_verified_at);
        $this->assertEquals('INFORME_ENVIADO', $this->project->fresh()->status);
        $this->postJson("/api/public/closures/{$this->report->id}/submit", ['items' => $this->fullItems()])->assertOk();
        $this->assertEquals('VERIFICANDO_FINALIZACION', $this->project->fresh()->status);
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
        $this->actingAs($this->resident)->post("/api/resident/projects/{$this->project->id}/photos", ['image' => $this->photo()]);
        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload());
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/audit-approval")->assertOk();

        // 10000 contratado − 3000 anticipo − (12−8)×50 disminución = 6800
        $this->assertEquals(6800.00, $this->report->fresh()->finiquito_amount);
        $this->assertTrue((bool) $this->project->fresh()->quality_verified);
    }

    public function test_final_payment_requires_procura_request(): void
    {
        $finanzas = User::factory()->create(['role' => 'FINANZAS']);

        $this->actingAs($finanzas)->postJson("/api/projects/{$this->project->id}/payments", ['paymentType' => 'FINAL', 'amount' => 100])->assertStatus(422);
    }

    private function toResidentStage(): void
    {
        $this->submitReport();
        $this->actingAs($this->resident)->post("/api/resident/projects/{$this->project->id}/photos", ['image' => $this->photo()]);
    }

    public function test_resident_must_measure_every_item_and_not_negative(): void
    {
        $this->toResidentStage();
        $url = "/api/resident/projects/{$this->project->id}/approval";

        $this->actingAs($this->resident)->postJson($url, ['items' => [['id' => $this->report->items->first()->id, 'residentQuantity' => 1]]])->assertStatus(422);
        $this->actingAs($this->resident)->postJson($url, $this->residentPayload(fn ($i, $row) => $i->name === 'Cable' ? [...$row, 'residentQuantity' => -1, 'note' => 'x'] : $row))->assertStatus(422);
        $this->assertEquals('INFORME_ENVIADO', $this->project->fresh()->status);
    }

    public function test_resident_difference_from_contractor_requires_a_note(): void
    {
        $this->toResidentStage();
        $url = "/api/resident/projects/{$this->project->id}/approval";

        $this->actingAs($this->resident)->postJson($url, $this->residentPayload(fn ($i, $row) => $i->name === 'Cable' ? [...$row, 'residentQuantity' => 90] : $row))->assertStatus(422);

        $this->actingAs($this->resident)->postJson($url, $this->residentPayload(fn ($i, $row) => $i->name === 'Cable' ? [...$row, 'residentQuantity' => 90, 'note' => 'Faltan 10 m'] : $row))->assertOk();
        $cable = $this->report->fresh()->items->firstWhere('name', 'Cable');
        $this->assertEquals(100, $cable->executed_quantity);
        $this->assertEquals(90, $cable->resident_quantity);
    }

    public function test_audit_approval_uses_resident_measurement_and_ignores_adjustments(): void
    {
        $this->toResidentStage();
        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload(fn ($i, $row) => $i->name === 'Cable' ? [...$row, 'residentQuantity' => 90, 'note' => 'Faltan 10 m'] : $row))->assertOk();
        $cable = $this->report->fresh()->items->firstWhere('name', 'Cable');

        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/audit-approval", ['items' => [['id' => $cable->id, 'auditQuantity' => 95]]])->assertOk();
        $this->assertEquals(90, $cable->fresh()->final_quantity);
        // 10000 − 3000 anticipo − (100−90)×2 = 6980
        $this->assertEquals(6980.00, $this->report->fresh()->finiquito_amount);
    }

    public function test_audit_rejection_requires_valid_target(): void
    {
        $this->toResidentStage();
        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload())->assertOk();
        $url = "/api/projects/{$this->project->id}/closure-report/rejection";

        $this->actingAs($this->auditoria)->postJson($url, ['reason' => 'x'])->assertUnprocessable();
        $this->actingAs($this->auditoria)->postJson($url, ['reason' => 'x', 'target' => 'OTRO'])->assertUnprocessable();
        $this->assertEquals('VERIFICANDO_FINALIZACION', $this->project->fresh()->status);
    }

    public function test_audit_rejection_to_resident_returns_report_without_contractor_link(): void
    {
        $this->toResidentStage();
        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload())->assertOk();
        $revision = $this->report->fresh()->revision;

        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/rejection", ['reason' => 'Remedir las tomas', 'target' => 'RESIDENTE'])
            ->assertOk()->assertJsonPath('data.status', 'INFORME_ENVIADO');

        $report = $this->report->fresh();
        $this->assertEquals('ENVIADO', $report->status);
        $this->assertEquals('RESIDENTE', $report->rejection_target);
        $this->assertEquals($revision + 1, $report->revision);
        foreach ($report->items as $item) {
            $this->assertNull($item->resident_quantity);
            $this->assertNotNull($item->executed_quantity);
        }

        $this->actingAs($this->resident)->getJson("/api/resident/projects/{$this->project->id}")
            ->assertJsonPath('data.closure.rejectionReason', 'Remedir las tomas')
            ->assertJsonPath('data.closure.rejectionTarget', 'RESIDENTE');

        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload())->assertOk();
        $this->assertNull($this->report->fresh()->rejection_reason);
        $this->assertEquals('VERIFICANDO_FINALIZACION', $this->project->fresh()->status);
    }

    public function test_audit_rejection_to_contractor_keeps_the_resident_measurement(): void
    {
        $this->toResidentStage();
        $this->actingAs($this->resident)->postJson("/api/resident/projects/{$this->project->id}/approval", $this->residentPayload())->assertOk();
        $this->actingAs($this->auditoria)->postJson("/api/projects/{$this->project->id}/closure-report/rejection", ['reason' => 'Revisar', 'target' => 'CONTRATISTA'])->assertOk();

        foreach ($this->report->fresh()->items as $item) {
            $this->assertNotNull($item->resident_quantity);
        }
    }

    public function test_public_link_never_exposes_resident_or_audit_measurements(): void
    {
        $this->getJson("/api/public/closures/{$this->report->id}")->assertJsonMissingPath('data.items.0.residentQuantity')->assertJsonMissingPath('data.items.0.auditQuantity');
    }
}
