<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectMaterial;
use App\Models\ProjectPayment;
use App\Models\ProjectProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ProjectHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $presidencia;

    protected function setUp(): void
    {
        parent::setUp();
        $this->presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);
        Contractor::factory()->create(['code' => 'CON-1', 'name' => 'Proveedor Uno']);
    }

    private function proposal(Project $project, string $id, float $total, array $extra = []): ProjectProposal
    {
        return ProjectProposal::create(array_merge([
            'id' => $id, 'project_id' => $project->id, 'contractor_code' => 'CON-1',
            'contractor_name_snapshot' => 'Proveedor Uno', 'material_cost' => $total, 'labor_cost' => 0,
            'total_cost' => $total, 'delivery_weeks' => 2, 'negotiated_advance_percent' => 30,
            'description' => 'p', 'origen' => 'MANUAL', 'fecha_oferta' => '2026-09-10',
        ], $extra));
    }

    private function document(Project $project, string $type, string $name, ?ProjectDocument $previous = null): ProjectDocument
    {
        $doc = ProjectDocument::create([
            'project_id' => $project->id, 'document_type' => $type, 'original_name' => $name,
            'stored_path' => "x/{$name}", 'mime_type' => 'application/pdf', 'size_bytes' => 1,
            'version_number' => $previous ? $previous->version_number + 1 : 1,
            'document_group_id' => $previous?->document_group_id,
        ]);
        if (!$previous) {
            $doc->update(['document_group_id' => $doc->id]);
        }

        return $doc;
    }

    /** Obra completa: adjudicada por encima de lo aprobado y pagada por encima de lo adjudicado. */
    private function fullProject(): Project
    {
        $project = Project::factory()->create([
            'status' => 'COMPLETADO_PAGADO', 'estimated_total' => 1400, 'approved_investment_amount' => 2200,
            'quality_verified' => true, 'completion_verified_date' => '2026-09-22',
        ]);
        ProjectMaterial::create(['id' => 'm-1', 'project_id' => $project->id, 'name' => 'Cemento', 'quantity' => 100, 'unit' => 'saco', 'estimated_unit_price' => 8]);
        ProjectMaterial::create(['id' => 'm-2', 'project_id' => $project->id, 'name' => 'Bloque', 'quantity' => 500, 'unit' => 'u', 'estimated_unit_price' => 1.2]);

        $original = $this->proposal($project, 'P-1', 2400);
        $renegotiated = $this->proposal($project, 'P-2', 2300, [
            'origen' => 'RENEGOCIACION', 'precio_anterior' => 2400, 'precio_nuevo' => 2300, 'diferencia' => -100, 'motivo' => 'Volumen',
        ]);
        $original->update(['replaced_by_id' => $renegotiated->id]);
        $project->update(['selected_proposal_id' => $renegotiated->id, 'selected_contractor_code' => 'CON-1']);

        $proofA = $this->document($project, 'COMPROBANTE_ANTICIPO', 'anticipo.pdf');
        $proofF = $this->document($project, 'COMPROBANTE_FINIQUITO', 'finiquito.pdf');
        ProjectPayment::create(['project_id' => $project->id, 'proposal_id' => 'P-2', 'payment_type' => 'ADVANCE', 'amount' => 700, 'paid_date' => '2026-09-16', 'bank' => 'Banesco', 'reference' => 'R1', 'comprobante_document_id' => $proofA->id]);
        ProjectPayment::create(['project_id' => $project->id, 'proposal_id' => 'P-2', 'payment_type' => 'FINAL', 'amount' => 1800, 'paid_date' => '2026-09-23', 'comprobante_document_id' => $proofF->id]);

        $v1 = $this->document($project, 'PLANO', 'plano.pdf');
        $this->document($project, 'PLANO', 'plano_corregido.pdf', $v1);
        AuditLog::record($project, 'INFRAESTRUCTURA', 'Creacion de peticion de obra', 'ok');
        AuditLog::record($project, 'FINANZAS', 'Liberacion total de fondos', null);

        return $project;
    }

    public function test_only_presidencia_admin_and_superadmin_can_read_history(): void
    {
        $project = $this->fullProject();
        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $admin = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($infra)->getJson('/api/project-history')->assertForbidden();
        $this->actingAs($infra)->getJson("/api/project-history/{$project->id}")->assertForbidden();
        $this->actingAs($admin)->getJson('/api/project-history')->assertOk();
        $this->actingAs($this->presidencia)->getJson("/api/project-history/{$project->id}")->assertOk();
    }

    public function test_list_returns_estimated_approved_awarded_executed_and_flags(): void
    {
        $project = $this->fullProject();

        $row = $this->actingAs($this->presidencia)->getJson('/api/project-history')
            ->assertOk()->json('items.0');

        $this->assertSame($project->id, $row['id']);
        $this->assertEquals(1400, $row['figures']['estimated']);
        $this->assertEquals(2200, $row['figures']['approved']);
        $this->assertEquals(2300, $row['figures']['awarded']);
        $this->assertEquals(2500, $row['figures']['executed']);
        $this->assertTrue($row['figures']['flags']['awardedExceedsApproved']);
        $this->assertTrue($row['figures']['flags']['executedExceedsAwarded']);
        $this->assertTrue($row['figures']['flags']['executedExceedsApproved']);
        $this->assertEquals(113.64, $row['figures']['executionPercent']);
    }

    public function test_unapproved_project_has_null_approved_and_no_silent_fallback(): void
    {
        Project::factory()->create(['status' => 'CREADO', 'estimated_total' => 900, 'approved_investment_amount' => null]);

        $figures = $this->actingAs($this->presidencia)->getJson('/api/project-history')->json('items.0.figures');

        $this->assertNull($figures['approved']);
        $this->assertNull($figures['executionPercent']);
        $this->assertTrue($figures['flags']['unapproved']);
        $this->assertEquals(0, $figures['executed']);
    }

    public function test_list_filters_by_status_search_and_alerts(): void
    {
        $over = $this->fullProject();
        $ok = Project::factory()->create(['status' => 'CREADO', 'title' => 'Pintura fachada', 'estimated_total' => 100, 'approved_investment_amount' => 500]);

        $ids = fn (string $qs) => collect($this->actingAs($this->presidencia)->getJson("/api/project-history?{$qs}")->assertOk()->json('items'))->pluck('id')->all();

        $this->assertSame([$over->id], $ids('withAlerts=1'));
        $this->assertSame([$ok->id], $ids('status=CREADO'));
        $this->assertSame([$ok->id], $ids('q=fachada'));
        $this->assertEqualsCanonicalizing([$over->id, $ok->id], $ids(''));
    }

    public function test_alerts_filter_catches_execution_above_award_even_if_within_approved(): void
    {
        $project = Project::factory()->create(['status' => 'COMPLETADO_PAGADO', 'estimated_total' => 100, 'approved_investment_amount' => 2200]);
        $this->proposal($project, 'P-9', 1940);
        $project->update(['selected_proposal_id' => 'P-9']);
        ProjectPayment::create(['project_id' => $project->id, 'proposal_id' => 'P-9', 'payment_type' => 'ADVANCE', 'amount' => 2100, 'paid_date' => '2026-09-16']);
        Project::factory()->create(['status' => 'CREADO', 'approved_investment_amount' => 500]);

        $ids = collect($this->actingAs($this->presidencia)->getJson('/api/project-history?withAlerts=1')->json('items'))->pluck('id')->all();

        $this->assertSame([$project->id], $ids);
    }

    public function test_list_row_includes_awarded_contractor_with_rating_or_null(): void
    {
        Contractor::where('code', 'CON-1')->update(['rating' => 4.5]);
        $awarded = $this->fullProject();
        $bare = Project::factory()->create(['status' => 'CREADO', 'created_date' => '2020-01-01']);

        $items = collect($this->actingAs($this->presidencia)->getJson('/api/project-history')->assertOk()->json('items'))->keyBy('id');

        $this->assertSame('Proveedor Uno', $items[$awarded->id]['contractor']['name']);
        $this->assertSame('CON-1', $items[$awarded->id]['contractor']['code']);
        $this->assertEquals(4.5, $items[$awarded->id]['contractor']['rating']);
        $this->assertNull($items[$bare->id]['contractor']);
    }

    public function test_list_filters_by_type_dates_and_location_search(): void
    {
        $a = Project::factory()->create(['type' => 'INFRAESTRUCTURA', 'location' => 'Caracas', 'created_date' => '2026-01-10']);
        $b = Project::factory()->create(['type' => 'MANTENIMIENTO', 'location' => 'Maracay', 'created_date' => '2026-03-10']);
        $ids = fn (string $qs) => collect($this->actingAs($this->presidencia)->getJson("/api/project-history?{$qs}")->assertOk()->json('items'))->pluck('id')->all();

        $this->assertSame([$a->id], $ids('type=INFRAESTRUCTURA'));
        $this->assertSame([$b->id], $ids('dateFrom=2026-02-01'));
        $this->assertSame([$a->id], $ids('dateTo=2026-02-01'));
        $this->assertSame([$b->id], $ids('q=maracay'));
        $this->assertSame([], $ids('dateFrom=2026-04-01'));
    }

    public function test_list_rejects_inverted_date_range_and_oversized_page(): void
    {
        $this->actingAs($this->presidencia)->getJson('/api/project-history?dateFrom=2026-05-01&dateTo=2026-01-01')->assertStatus(422);
        $this->actingAs($this->presidencia)->getJson('/api/project-history?perPage=500')->assertStatus(422);
    }

    public function test_list_paginates_and_reports_totals(): void
    {
        Project::factory()->count(5)->create();

        $page2 = $this->actingAs($this->presidencia)->getJson('/api/project-history?perPage=2&page=2')->assertOk()->json();

        $this->assertSame(5, $page2['total']);
        $this->assertSame(3, $page2['lastPage']);
        $this->assertSame(2, $page2['currentPage']);
        $this->assertCount(2, $page2['items']);
    }

    public function test_search_treats_like_wildcards_literally(): void
    {
        Project::factory()->create(['title' => 'Obra 100% lista']);
        Project::factory()->create(['title' => 'Obra cualquiera']);

        $items = $this->actingAs($this->presidencia)->getJson('/api/project-history?q=' . urlencode('%'))->assertOk()->json('items');

        $this->assertCount(1, $items);
        $this->assertSame('Obra 100% lista', $items[0]['title']);
    }

    public function test_export_returns_all_filtered_rows_without_pagination_and_is_role_protected(): void
    {
        Project::factory()->count(20)->create(['type' => 'MANTENIMIENTO']);
        Project::factory()->create(['type' => 'INFRAESTRUCTURA']);

        $all = $this->actingAs($this->presidencia)->getJson('/api/project-history/export')->assertOk()->json('items');
        $filtered = $this->actingAs($this->presidencia)->getJson('/api/project-history/export?type=INFRAESTRUCTURA')->assertOk()->json('items');

        $this->assertCount(21, $all);
        $this->assertCount(1, $filtered);
        $this->assertArrayHasKey('figures', $all[0]);

        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->actingAs($infra)->getJson('/api/project-history/export')->assertForbidden();
    }

    public function test_export_route_is_not_swallowed_by_the_project_wildcard(): void
    {
        $this->actingAs($this->presidencia)->getJson('/api/project-history/export')->assertOk()->assertJsonStructure(['items']);
        $this->actingAs($this->presidencia)->getJson('/api/project-history/NO-EXISTE')->assertNotFound();
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/api/project-history')->assertUnauthorized();
        $this->getJson('/api/project-history/export')->assertUnauthorized();
    }

    public function test_list_query_count_does_not_grow_with_number_of_projects(): void
    {
        Project::factory()->count(3)->create();
        DB::enableQueryLog();
        $this->actingAs($this->presidencia)->getJson('/api/project-history')->assertOk();
        $small = count(DB::getQueryLog());

        Project::factory()->count(12)->create();
        DB::flushQueryLog();
        $this->actingAs($this->presidencia)->getJson('/api/project-history')->assertOk();

        $this->assertSame($small, count(DB::getQueryLog()));
    }

    public function test_detail_builds_the_full_chain(): void
    {
        $project = $this->fullProject();

        $d = $this->actingAs($this->presidencia)->getJson("/api/project-history/{$project->id}")->assertOk()->json('data');

        $this->assertEquals(1400, $d['budget']['linesTotal']);
        $this->assertCount(2, $d['budget']['lines']);

        $this->assertCount(2, $d['suppliers']);
        $original = collect($d['suppliers'])->firstWhere('id', 'P-1');
        $this->assertSame('P-2', $original['replacedById']);
        $this->assertFalse($original['isAwarded']);
        $this->assertTrue(collect($d['suppliers'])->firstWhere('id', 'P-2')['isAwarded']);

        $this->assertSame('P-2', $d['award']['proposalId']);

        $this->assertCount(2, $d['payments']['items']);
        $this->assertEquals(2500, $d['payments']['total']);
        $this->assertSame(0, $d['payments']['withoutProof']);
        $this->assertSame('anticipo.pdf', collect($d['payments']['items'])->firstWhere('type', 'ADVANCE')['proof']['name']);
        $this->assertSame('Banesco', collect($d['payments']['items'])->firstWhere('type', 'ADVANCE')['bank']);

        $this->assertCount(1, $d['drawings']);
        $this->assertSame(2, $d['drawings'][0]['currentVersion']);
        $this->assertCount(2, $d['drawings'][0]['versions']);

        $this->assertTrue($d['closure']['isClosed']);
        $this->assertTrue($d['closure']['qualityVerified']);
        $this->assertSame('2026-09-22', $d['closure']['completionVerifiedDate']);

        $this->assertSame(['obra', 'presupuesto', 'solicitud', 'proveedores', 'adjudicacion', 'pagos', 'planos', 'cierre'], array_column($d['stages'], 'key'));
        $this->assertSame(['done'], array_values(array_unique(array_column($d['stages'], 'state'))));
        $this->assertCount(2, $d['timeline']);
        $this->assertSame('INFRAESTRUCTURA', $d['timeline'][0]['role']);
    }

    public function test_detail_of_a_project_without_awards_or_payments_is_partial_not_broken(): void
    {
        $project = Project::factory()->create(['status' => 'CREADO', 'approved_investment_amount' => null]);

        $d = $this->actingAs($this->presidencia)->getJson("/api/project-history/{$project->id}")->assertOk()->json('data');

        $this->assertNull($d['award']);
        $this->assertSame([], $d['payments']['items']);
        $this->assertNull($d['payments']['percentOfAwarded']);
        $this->assertFalse($d['closure']['isClosed']);
        $this->assertSame('pending', collect($d['stages'])->firstWhere('key', 'cierre')['state']);
    }

    public function test_deleted_drawing_versions_stay_in_history_flagged(): void
    {
        $project = Project::factory()->create();
        $v1 = $this->document($project, 'PLANO', 'a.pdf');
        $v2 = $this->document($project, 'PLANO', 'a2.pdf', $v1);
        $v2->delete();

        $d = $this->actingAs($this->presidencia)->getJson("/api/project-history/{$project->id}")->json('data.drawings.0');

        $this->assertSame(1, $d['currentVersion']);
        $this->assertTrue($d['versions'][1]['isDeleted']);
    }
}
