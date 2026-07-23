<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\Project;
use App\Models\ProjectPayment;
use App\Models\ProjectProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProjectLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private User $infra;
    private User $cierre;
    private User $procura;
    private User $analista;
    private User $finanzas;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $this->cierre = User::factory()->create(['role' => 'CIERRE_DE_OBRA']);
        $this->procura = User::factory()->create(['role' => 'PROCURA']);
        $this->analista = User::factory()->create(['role' => 'ANALISTA']);
        $this->finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $this->contractor = Contractor::factory()->create();
        MaterialCatalog::factory()->count(3)->create();
    }

    public function test_create_project_with_materials(): void
    {
        $this->actingAs($this->infra);

        $response = $this->postJson('/api/projects', [
            'title'       => 'Nuevo Proyecto Test',
            'type'        => 'INFRAESTRUCTURA',
            'description' => 'Descripción del proyecto de prueba',
            'location'    => 'Ciudad de Prueba',
            'materials'   => [
                [
                    'name'               => 'Cemento',
                    'quantity'           => 100,
                    'unit'               => 'kg',
                    'estimatedUnitPrice' => 12.50,
                ],
                [
                    'name'               => 'Acero',
                    'quantity'           => 50,
                    'unit'               => 'm',
                    'estimatedUnitPrice' => 25.00,
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'data' => [
                'id', 'title', 'type', 'status', 'location',
                'materials', 'estimatedTotal',
            ],
        ]);

        $projectId = $response->json('data.id');
        $this->assertStringStartsWith('PRJ-', $projectId);
        $this->assertEquals('CREADO', $response->json('data.status'));
        $this->assertEquals(2500.00, (float) $response->json('data.estimatedTotal')); // 100*12.50 + 50*25
        $this->assertCount(2, $response->json('data.materials'));

        // Audit log created
        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $projectId,
            'action'     => 'Creacion de peticion de obra',
            'role'       => 'INFRAESTRUCTURA',
        ]);
    }

    public function test_review_project_cierre(): void
    {
        $project = Project::factory()->create(['status' => 'CREADO']);

        $response = $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$project->id}/review", [
                'notes'             => 'Planos aprobados con correcciones menores',
                'blueprintsCount'   => 3,
                'calculationsAdded' => true,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'REVISADO_CIERRE');
        $response->assertJsonPath('data.blueprintsCount', 3);
        $response->assertJsonPath('data.calculationsAdded', true);
        $response->assertJsonPath('data.cierreObraNotes', 'Planos aprobados con correcciones menores');

        // Audit log
        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id,
            'role'       => 'CIERRE_DE_OBRA',
            'action'     => 'Revision tecnica de calculos y planos',
        ]);
    }

    public function test_approve_investment(): void
    {
        $project = Project::factory()->reviewed()->create();

        $response = $this->actingAs($this->procura)
            ->postJson("/api/projects/{$project->id}/approve-investment", [
                'notes'                   => 'Inversión aprobada para licitación',
                'approvedInvestmentAmount' => 50000.00,
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'CONFIRMADO_PROCURA');
        // PHP json_encode serializes 50000.0 as 50000 (int) when decimal is .0
        $response->assertJsonPath('data.approvedInvestmentAmount', 50000);

        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id,
            'role'       => 'PROCURA',
            'action'     => 'Confirmacion de presupuesto y envio a licitacion',
        ]);
    }

    public function test_add_and_remove_proposals(): void
    {
        $project = Project::factory()->confirmed()->create();
        $contractor1 = Contractor::factory()->create();
        $contractor2 = Contractor::factory()->create();

        // Add proposal 1
        $response = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals", [
                'contractorCode'          => $contractor1->code,
                'materialCost'            => 20000.00,
                'laborCost'               => 8000.00,
                'totalCost'               => 28000.00,
                'deliveryWeeks'           => 12,
                'negotiatedAdvancePercent' => 30,
                'description'             => 'Propuesta económica detallada',
            ]);

        $response->assertStatus(200);
        $proposalId = $response->json('data.proposals')[0]['id'] ?? null;
        $this->assertNotNull($proposalId);

        // Add proposal 2
        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals", [
                'contractorCode'          => $contractor2->code,
                'materialCost'            => 18000.00,
                'laborCost'               => 7000.00,
                'totalCost'               => 25000.00,
                'deliveryWeeks'           => 10,
                'negotiatedAdvancePercent' => 25,
                'description'             => 'Segunda propuesta',
            ])
            ->assertStatus(200);

        $this->assertCount(2, $project->fresh()->proposals);

        // Remove proposal 1
        $response = $this->actingAs($this->analista)
            ->deleteJson("/api/projects/{$project->id}/proposals/{$proposalId}");

        $response->assertStatus(200);
        $this->assertCount(1, $project->fresh()->proposals);
        $this->assertDatabaseMissing('project_proposals', ['id' => $proposalId]);
    }

    public function test_full_project_lifecycle(): void
    {
        // 1. Create project (INFRAESTRUCTURA)
        $createResponse = $this->actingAs($this->infra)
            ->postJson('/api/projects', [
                'title'       => 'Proyecto Ciclo Completo',
                'type'        => 'MANTENIMIENTO',
                'description' => 'Prueba del ciclo de vida completo',
                'location'    => 'Sede Central',
                'materials'   => [
                    ['name' => 'Pintura', 'quantity' => 200, 'unit' => 'litro', 'estimatedUnitPrice' => 15.00],
                    ['name' => 'Brochas', 'quantity' => 30, 'unit' => 'unidad', 'estimatedUnitPrice' => 5.00],
                ],
            ]);
        $createResponse->assertStatus(201);
        $projectId = $createResponse->json('data.id');

        // 2. Review (CIERRE_DE_OBRA)
        $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$projectId}/review", [
                'notes'             => 'Revisión completa',
                'blueprintsCount'   => 5,
                'calculationsAdded' => true,
            ])
            ->assertJsonPath('data.status', 'REVISADO_CIERRE');

        // 3. Approve investment (PROCURA)
        $this->actingAs($this->procura)
            ->postJson("/api/projects/{$projectId}/approve-investment", [
                'notes'                   => 'Aprobado',
                'approvedInvestmentAmount' => 30000.00,
            ])
            ->assertJsonPath('data.status', 'CONFIRMADO_PROCURA');

        // 4. Add proposal (ANALISTA)
        $proposalResponse = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$projectId}/proposals", [
                'contractorCode'          => $this->contractor->code,
                'materialCost'            => 15000.00,
                'laborCost'               => 5000.00,
                'totalCost'               => 20000.00,
                'deliveryWeeks'           => 8,
                'negotiatedAdvancePercent' => 30,
                'description'             => 'Propuesta principal',
            ]);
        $proposalResponse->assertStatus(200);
        $proposalId = $proposalResponse->json('data.proposals')[0]['id'];

        // 5. Submit comparative (ANALISTA)
        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$projectId}/submit-comparative")
            ->assertJsonPath('data.status', 'COMPARATIVA_ENVIADA');

        // 6. Select contractor (PROCURA)
        $this->actingAs($this->procura)
            ->postJson("/api/projects/{$projectId}/select-contractor", [
                'contractorCode' => $this->contractor->code,
                'proposalId'     => $proposalId,
            ])
            ->assertJsonPath('data.status', 'CONTRATADO');

        // 7. Pay advance (FINANZAS)
        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$projectId}/payments", [
                'paymentType' => 'ADVANCE',
                'amount'      => 6000.00,
                'notes'       => 'Anticipo del 30%',
            ])
            ->assertJsonPath('data.status', 'EN_EJECUCION');

        $this->assertDatabaseHas('project_payments', [
            'project_id'   => $projectId,
            'payment_type' => 'ADVANCE',
            'amount'       => 6000.00,
        ]);

        // 8. Report finished (CIERRE_DE_OBRA)
        $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$projectId}/report-finished")
            ->assertJsonPath('data.status', 'VERIFICANDO_FINALIZACION');

        // 9. Verify completion (CIERRE_DE_OBRA) — approve
        $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$projectId}/verify-completion", [
                'qualityVerified'        => true,
                'completionVerifiedDate' => '2026-07-22',
                'details'                => 'Trabajo verificado satisfactoriamente',
            ])
            ->assertJsonPath('data.status', 'LISTO_PAGO_FINAL');

        // 10. Pay final (FINANZAS)
        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$projectId}/payments", [
                'paymentType' => 'FINAL',
                'amount'      => 14000.00,
                'notes'       => 'Pago final de obra',
            ])
            ->assertJsonPath('data.status', 'COMPLETADO_PAGADO');

        // Verify final DB state
        $this->assertDatabaseHas('project_payments', [
            'project_id'   => $projectId,
            'payment_type' => 'FINAL',
            'amount'       => 14000.00,
        ]);

        // Audit logs generated for every step
        $this->assertGreaterThanOrEqual(7, AuditLog::where('project_id', $projectId)->count());
    }

    public function test_reject_proposals_returns_to_previous_state(): void
    {
        $project = Project::factory()->confirmed()->create();
        $contractor = Contractor::factory()->create();

        // Add proposal
        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals", [
                'contractorCode'          => $contractor->code,
                'materialCost'            => 10000,
                'laborCost'               => 5000,
                'totalCost'               => 15000,
                'deliveryWeeks'           => 6,
                'negotiatedAdvancePercent' => 30,
                'description'             => 'Propuesta a rechazar',
            ])->assertStatus(200);

        // Submit comparative
        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/submit-comparative")
            ->assertJsonPath('data.status', 'COMPARATIVA_ENVIADA');

        // Reject all proposals
        $response = $this->actingAs($this->procura)
            ->postJson("/api/projects/{$project->id}/reject-proposals", [
                'reason' => 'Presupuestos exceden el monto autorizado',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'CONFIRMADO_PROCURA');
        // Proposals deleted
        $this->assertCount(0, $project->fresh()->proposals);
    }

    public function test_verify_completion_rejects_and_returns_to_execution(): void
    {
        $project = Project::factory()->create(['status' => 'VERIFICANDO_FINALIZACION']);

        $response = $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$project->id}/verify-completion", [
                'qualityVerified' => false,
                'details'         => 'Se requieren correcciones en instalaciones eléctricas',
            ]);

        $response->assertJsonPath('data.status', 'EN_EJECUCION');
        $response->assertJsonPath('data.qualityVerified', false);
    }

    public function test_index_lists_projects_with_filters(): void
    {
        Project::factory()->create(['type' => 'INFRAESTRUCTURA']);
        Project::factory()->create(['type' => 'INFRAESTRUCTURA']);
        Project::factory()->create(['type' => 'INFRAESTRUCTURA']);
        Project::factory()->reviewed()->create(['type' => 'MANTENIMIENTO']);

        // List all
        $response = $this->actingAs($this->infra)
            ->getJson('/api/projects');
        $response->assertStatus(200);
        $this->assertCount(4, $response->json('data'));

        // Filter by type
        $response = $this->actingAs($this->infra)
            ->getJson('/api/projects?type=MANTENIMIENTO');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));

        // Filter by status
        $response = $this->actingAs($this->infra)
            ->getJson('/api/projects?status=REVISADO_CIERRE');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_submit_comparative_without_proposals_returns_422(): void
    {
        $project = Project::factory()->confirmed()->create();

        $response = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/submit-comparative");

        $response->assertStatus(422);
    }

    public function test_remove_awarded_proposal_returns_422(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $this->contractor->code,
        ]);
        $project->update(['selected_proposal_id' => $proposal->id]);

        $response = $this->actingAs($this->analista)
            ->deleteJson("/api/projects/{$project->id}/proposals/{$proposal->id}");

        $response->assertStatus(422);
    }

    public function test_show_returns_single_project(): void
    {
        $project = Project::factory()->create();

        $response = $this->actingAs($this->infra)
            ->getJson("/api/projects/{$project->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $project->id);
    }
}
