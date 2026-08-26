<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\MaterialCatalog;
use App\Models\Project;
use App\Models\ProjectPayment;
use App\Models\ProjectProposal;
use App\Models\User;
use App\Services\SettingsService;
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
                    'condition'          => 'NUEVO',
                ],
                [
                    'name'               => 'Acero',
                    'quantity'           => 50,
                    'unit'               => 'm',
                    'estimatedUnitPrice' => 25.00,
                    'condition'          => 'NUEVO',
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

        // documents también viene cargado en la respuesta de store() (antes faltaba)
        $this->assertArrayHasKey('documents', $response->json('data'));
    }

    public function test_create_project_with_material_characteristics(): void
    {
        $this->actingAs($this->infra);

        $response = $this->postJson('/api/projects', [
            'title'       => 'Proyecto con características',
            'type'        => 'INFRAESTRUCTURA',
            'description' => 'Descripción del proyecto de prueba',
            'location'    => 'Ciudad de Prueba',
            'materials'   => [
                [
                    'name'               => 'Motor usado',
                    'quantity'           => 1,
                    'unit'               => 'unidad',
                    'estimatedUnitPrice' => 500,
                    'condition'          => 'USADO',
                    'warrantyValue'      => 6,
                    'warrantyUnit'       => 'MESES',
                    'brand'              => 'Bosch',
                    'model'              => 'X200',
                    'specifications'     => '2HP, 220V',
                    'observations'       => 'Revisar antes de instalar',
                ],
                [
                    'name'               => 'Sin características',
                    'quantity'           => 2,
                    'unit'               => 'unidad',
                    'estimatedUnitPrice' => 10,
                    'condition'          => 'AMBAS',
                ],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('data.materials.0.condition', 'USADO');
        $response->assertJsonPath('data.materials.0.warrantyValue', 6);
        $response->assertJsonPath('data.materials.0.warrantyUnit', 'MESES');
        $response->assertJsonPath('data.materials.0.brand', 'Bosch');
        $response->assertJsonPath('data.materials.0.model', 'X200');
        $response->assertJsonPath('data.materials.0.specifications', '2HP, 220V');
        $response->assertJsonPath('data.materials.0.observations', 'Revisar antes de instalar');

        $response->assertJsonPath('data.materials.1.condition', 'AMBAS');
        $response->assertJsonPath('data.materials.1.warrantyValue', null);
        $response->assertJsonPath('data.materials.1.warrantyUnit', null);
    }

    public function test_review_project_cierre(): void
    {
        $project = Project::factory()->create(['status' => 'CREADO']);

        $response = $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$project->id}/review", [
                'notes' => 'Planos aprobados con correcciones menores',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'REVISADO_CIERRE');
        $response->assertJsonPath('data.cierreObraNotes', 'Planos aprobados con correcciones menores');

        // Audit log
        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id,
            'role'       => 'CIERRE_DE_OBRA',
            'action'     => 'Revision tecnica de calculos y planos',
        ]);
    }

    public function test_review_project_cierre_without_notes(): void
    {
        $project = Project::factory()->create(['status' => 'CREADO']);

        $response = $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$project->id}/review", []);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'REVISADO_CIERRE');
    }

    public function test_reject_project_from_creado(): void
    {
        $project = Project::factory()->create(['status' => 'CREADO']);

        $response = $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$project->id}/reject-project", [
                'reason' => 'La descripción no detalla el alcance del trabajo.',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'RECHAZADO_CIERRE');

        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id,
            'role'       => 'CIERRE_DE_OBRA',
            'action'     => 'Rechazo de petición de obra',
        ]);
        $log = \App\Models\AuditLog::where('project_id', $project->id)
            ->where('action', 'Rechazo de petición de obra')->first();
        $this->assertStringContainsString('La descripción no detalla el alcance del trabajo.', $log->details);
    }

    public function test_reject_project_persists_observations_separately_from_reason(): void
    {
        $project = Project::factory()->create(['status' => 'CREADO']);

        $response = $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$project->id}/reject-project", [
                'reason' => 'La descripción no detalla el alcance del trabajo.',
                'observations' => 'Revisar también la cubicación de concreto.',
            ]);

        $response->assertStatus(200);

        $log = \App\Models\AuditLog::where('project_id', $project->id)
            ->where('action', 'Rechazo de petición de obra')->first();
        $this->assertStringContainsString('La descripción no detalla el alcance del trabajo.', $log->details);
        $this->assertStringNotContainsString('Revisar también la cubicación de concreto.', $log->details);
        $this->assertSame('Revisar también la cubicación de concreto.', $log->observations);
    }

    public function test_upload_correccion_document_after_rejection(): void
    {
        $project = Project::factory()->create(['status' => 'CREADO']);
        $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$project->id}/reject-project", ['reason' => 'Motivo cualquiera'])
            ->assertStatus(200);

        $file = \Illuminate\Http\UploadedFile::fake()->create('correccion.pdf', 100, 'application/pdf');

        $response = $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$project->id}/documents", [
                'document_type' => 'CORRECCION',
                'files' => [$file],
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('project_documents', [
            'project_id' => $project->id,
            'document_type' => 'CORRECCION',
        ]);
    }

    private function makeDocument(Project $project, string $type = 'FOTO'): \App\Models\ProjectDocument
    {
        $doc = \App\Models\ProjectDocument::create([
            'project_id' => $project->id,
            'document_type' => $type,
            'original_name' => 'test.jpg',
            'stored_path' => "project-documents/{$project->id}/{$type}/test.jpg",
            'mime_type' => 'image/jpeg',
            'size_bytes' => 100,
            'version_number' => 1,
        ]);
        $doc->update(['document_group_id' => $doc->id]);

        return $doc;
    }

    public function test_infraestructura_can_delete_document_while_rechazado_cierre(): void
    {
        $project = Project::factory()->create(['status' => 'RECHAZADO_CIERRE']);
        $doc = $this->makeDocument($project);

        $response = $this->actingAs($this->infra)
            ->deleteJson("/api/projects/{$project->id}/documents/{$doc->id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('project_documents', ['id' => $doc->id]);
        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id,
            'role' => 'INFRAESTRUCTURA',
            'action' => 'Eliminacion de documento adjunto (todas las versiones)',
        ]);
    }

    public function test_infraestructura_cannot_delete_document_outside_rechazado_cierre(): void
    {
        $project = Project::factory()->create(['status' => 'CREADO']);
        $doc = $this->makeDocument($project);

        $response = $this->actingAs($this->infra)
            ->deleteJson("/api/projects/{$project->id}/documents/{$doc->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('project_documents', ['id' => $doc->id]);
    }

    public function test_infraestructura_cannot_delete_correccion_document(): void
    {
        $project = Project::factory()->create(['status' => 'RECHAZADO_CIERRE']);
        $doc = $this->makeDocument($project, 'CORRECCION');

        $response = $this->actingAs($this->infra)
            ->deleteJson("/api/projects/{$project->id}/documents/{$doc->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('project_documents', ['id' => $doc->id]);
    }

    public function test_reject_project_fails_from_non_creado_status(): void
    {
        $project = Project::factory()->reviewed()->create();

        $response = $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$project->id}/reject-project", [
                'reason' => 'Motivo cualquiera',
            ]);

        $response->assertStatus(422);
    }

    public function test_resubmit_project_after_rejection(): void
    {
        $project = Project::factory()->create(['status' => 'RECHAZADO_CIERRE']);
        $project->materials()->create([
            'id' => $project->id . '-MAT-1',
            'name' => 'Cemento viejo',
            'quantity' => 1,
            'unit' => 'Saco',
            'estimated_unit_price' => 10,
            'condition' => 'NUEVO',
        ]);

        $response = $this->actingAs($this->infra)
            ->postJson("/api/projects/{$project->id}/resubmit", [
                'title'       => 'Título corregido',
                'description' => 'Descripción corregida y detallada',
                'location'    => 'Ubicación corregida',
                'materials'   => [
                    ['name' => 'Cemento nuevo', 'quantity' => 5, 'unit' => 'Saco', 'estimatedUnitPrice' => 12, 'condition' => 'NUEVO'],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.status', 'CREADO');
        $response->assertJsonPath('data.title', 'Título corregido');
        $this->assertCount(1, $response->json('data.materials'));
        $response->assertJsonPath('data.materials.0.name', 'Cemento nuevo');

        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $project->id,
            'role'       => 'INFRAESTRUCTURA',
            'action'     => 'Reenvío de petición corregida',
        ]);
    }

    public function test_resubmit_project_clears_stale_dossier_ai_evaluation(): void
    {
        $project = Project::factory()->create([
            'status' => 'RECHAZADO_CIERRE',
            'dossier_ai_score' => 90,
            'dossier_ai_summary' => 'Análisis del expediente antes de la corrección.',
            'dossier_ai_alerts' => ['Alguna alerta vieja.'],
            'dossier_ai_recommendation' => 'Proceder.',
            'dossier_ai_suggested_amount' => 5000,
            'dossier_ai_completeness_factors' => ['documentation' => 80, 'budgetConsistency' => 90, 'rejectionRisk' => 100],
            'dossier_ai_provider' => 'openai',
            'dossier_ai_evaluated_at' => now()->subDay(),
        ]);

        $response = $this->actingAs($this->infra)
            ->postJson("/api/projects/{$project->id}/resubmit", [
                'title'       => 'Título corregido',
                'description' => 'Descripción corregida y detallada',
                'location'    => 'Ubicación corregida',
                'materials'   => [
                    ['name' => 'Cemento nuevo', 'quantity' => 5, 'unit' => 'Saco', 'estimatedUnitPrice' => 12, 'condition' => 'NUEVO'],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('data.dossierAiScore', null);
        $response->assertJsonPath('data.dossierAiEvaluatedAt', null);

        $project->refresh();
        $this->assertNull($project->dossier_ai_score);
        $this->assertNull($project->dossier_ai_summary);
        $this->assertNull($project->dossier_ai_alerts);
        $this->assertNull($project->dossier_ai_evaluated_at);
    }

    public function test_resubmit_project_fails_from_non_rechazado_status(): void
    {
        $project = Project::factory()->create(['status' => 'CREADO']);

        $response = $this->actingAs($this->infra)
            ->postJson("/api/projects/{$project->id}/resubmit", [
                'title'       => 'Título',
                'description' => 'Descripción',
                'location'    => 'Ubicación',
                'materials'   => [
                    ['name' => 'Cemento', 'quantity' => 1, 'unit' => 'Saco', 'estimatedUnitPrice' => 10, 'condition' => 'NUEVO'],
                ],
            ]);

        $response->assertStatus(422);
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
                'origen'                  => 'MANUAL',
                'fechaOferta'             => '2026-07-01',
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
                'origen'                  => 'MANUAL',
                'fechaOferta'             => '2026-07-01',
            ])
            ->assertStatus(200);

        $this->assertCount(2, $project->fresh()->proposals);

        // Remove proposal 1
        $response = $this->actingAs($this->analista)
            ->deleteJson("/api/projects/{$project->id}/proposals/{$proposalId}");

        $response->assertStatus(200);
        $this->assertCount(1, $project->fresh()->proposals);
        $this->assertSoftDeleted('project_proposals', ['id' => $proposalId]);
    }

    public function test_add_proposal_accepts_advance_percent_above_configured_max_with_motivo(): void
    {
        // El anticipo negociado puede exceder el máximo configurado en CONFIG
        // APP (renegociación telefónica/directa con el proveedor) — el máximo
        // configurado solo dispara una alerta visual en el frontend, nunca
        // bloquea el registro de la propuesta, siempre que se justifique con
        // un motivo obligatorio.
        $project = Project::factory()->confirmed()->create();
        $contractor = Contractor::factory()->create();

        AppSetting::where('key', 'anticipo_maximo_porcentaje')->update(['value' => '20']);
        SettingsService::forget();

        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals", [
                'contractorCode'           => $contractor->code,
                'materialCost'             => 20000.00,
                'laborCost'                => 8000.00,
                'totalCost'                => 28000.00,
                'deliveryWeeks'            => 12,
                'negotiatedAdvancePercent' => 30,
                'description'              => 'Anticipo renegociado por encima del máximo configurado',
                'origen'                   => 'MANUAL',
                'fechaOferta'              => '2026-07-01',
                'motivo'                   => 'Proveedor exige anticipo mayor por escasez de materiales importados.',
            ])
            ->assertStatus(200);
    }

    public function test_add_proposal_rejects_advance_percent_above_configured_max_without_motivo(): void
    {
        // Sin motivo, exceder el máximo configurado ahora se rechaza — el
        // motivo es lo que documenta/audita la excepción, ya no queda
        // silenciosa.
        $project = Project::factory()->confirmed()->create();
        $contractor = Contractor::factory()->create();

        AppSetting::where('key', 'anticipo_maximo_porcentaje')->update(['value' => '20']);
        SettingsService::forget();

        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals", [
                'contractorCode'           => $contractor->code,
                'materialCost'             => 20000.00,
                'laborCost'                => 8000.00,
                'totalCost'                => 28000.00,
                'deliveryWeeks'            => 12,
                'negotiatedAdvancePercent' => 30,
                'description'              => 'Anticipo por encima del máximo, sin justificar',
                'origen'                   => 'MANUAL',
                'fechaOferta'              => '2026-07-01',
            ])
            ->assertStatus(422);
    }

    public function test_add_proposal_rejects_advance_percent_above_sanity_ceiling(): void
    {
        $project = Project::factory()->confirmed()->create();
        $contractor = Contractor::factory()->create();

        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals", [
                'contractorCode'           => $contractor->code,
                'materialCost'             => 20000.00,
                'laborCost'                => 8000.00,
                'totalCost'                => 28000.00,
                'deliveryWeeks'            => 12,
                'negotiatedAdvancePercent' => 150,
                'description'              => 'Anticipo por encima del 100%',
                'origen'                   => 'MANUAL',
                'fechaOferta'              => '2026-07-01',
            ])
            ->assertStatus(422);
    }

    public function test_add_proposal_with_renegociacion_requires_precio_anterior_nuevo_y_motivo(): void
    {
        $project = Project::factory()->confirmed()->create();
        $contractor = Contractor::factory()->create();

        $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals", [
                'contractorCode'           => $contractor->code,
                'materialCost'             => 20000.00,
                'laborCost'                => 8000.00,
                'totalCost'                => 28000.00,
                'deliveryWeeks'            => 12,
                'negotiatedAdvancePercent' => 15,
                'description'              => 'Oferta renegociada',
                'origen'                   => 'RENEGOCIACION',
                'fechaOferta'              => '2026-07-01',
            ])
            ->assertStatus(422);

        $response = $this->actingAs($this->analista)
            ->postJson("/api/projects/{$project->id}/proposals", [
                'contractorCode'           => $contractor->code,
                'materialCost'             => 20000.00,
                'laborCost'                => 8000.00,
                'totalCost'                => 28000.00,
                'deliveryWeeks'            => 12,
                'negotiatedAdvancePercent' => 15,
                'description'              => 'Oferta renegociada',
                'origen'                   => 'RENEGOCIACION',
                'fechaOferta'              => '2026-07-01',
                'precioAnterior'           => 32000.00,
                'precioNuevo'              => 28000.00,
                'motivo'                   => 'Renegociación directa: el contratista bajó el precio tras revisar cantidades.',
            ]);

        $response->assertStatus(200);
        $proposalId = $response->json('data.proposals')[0]['id'];
        $this->assertDatabaseHas('project_proposals', [
            'id' => $proposalId,
            'origen' => 'RENEGOCIACION',
            'precio_anterior' => 32000.00,
            'precio_nuevo' => 28000.00,
            'diferencia' => -4000.00,
        ]);
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
                    ['name' => 'Pintura', 'quantity' => 200, 'unit' => 'litro', 'estimatedUnitPrice' => 15.00, 'condition' => 'NUEVO'],
                    ['name' => 'Brochas', 'quantity' => 30, 'unit' => 'unidad', 'estimatedUnitPrice' => 5.00, 'condition' => 'NUEVO'],
                ],
            ]);
        $createResponse->assertStatus(201);
        $projectId = $createResponse->json('data.id');

        // 2. Review (CIERRE_DE_OBRA)
        $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$projectId}/review", [
                'notes' => 'Revisión completa',
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
                'origen'                  => 'MANUAL',
                'fechaOferta'             => '2026-07-01',
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
                'origen'                  => 'MANUAL',
                'fechaOferta'             => '2026-07-01',
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
        // Proposals excluded from active queries (soft-deleted, not physically removed)
        $this->assertCount(0, $project->fresh()->proposals);
        $this->assertCount(1, \App\Models\ProjectProposal::withTrashed()->where('project_id', $project->id)->get());
        $this->assertSoftDeleted('project_proposals', ['project_id' => $project->id]);
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
        $response->assertJsonFragment(['message' => 'No se puede eliminar una propuesta adjudicada.']);
    }

    public function test_show_returns_single_project(): void
    {
        $project = Project::factory()->create();

        $response = $this->actingAs($this->infra)
            ->getJson("/api/projects/{$project->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $project->id);
    }

    // ── Guardas de estado (bug de integridad financiera, auditoría V3 A-6) ──
    // Antes de este fix, ninguno de estos 4 endpoints validaba el estado
    // del proyecto: FINANZAS podía pagar un proyecto recién creado, o
    // reabrir uno ya cerrado y pagado. El middleware `role:` solo valida
    // *quién* puede llamar, no *cuándo* es válido hacerlo.

    public function test_select_contractor_rejects_project_not_in_comparativa_enviada(): void
    {
        $project = Project::factory()->create(['status' => 'CREADO']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $this->contractor->code,
        ]);

        $response = $this->actingAs($this->procura)
            ->postJson("/api/projects/{$project->id}/select-contractor", [
                'contractorCode' => $this->contractor->code,
                'proposalId'     => $proposal->id,
            ]);

        $response->assertStatus(422);
        $this->assertEquals('CREADO', $project->fresh()->status);
    }

    public function test_pay_advance_rejects_project_not_in_contratado(): void
    {
        // Escenario exacto de la auditoría: FINANZAS paga un proyecto
        // recién creado, saltándose adjudicación, ejecución y verificación.
        $project = Project::factory()->create(['status' => 'CREADO']);

        $response = $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", [
                'paymentType' => 'ADVANCE',
                'amount'      => 999999,
            ]);

        $response->assertStatus(422);
        $this->assertEquals('CREADO', $project->fresh()->status);
        $this->assertDatabaseMissing('project_payments', ['project_id' => $project->id]);
    }

    public function test_pay_final_rejects_project_not_in_listo_pago_final(): void
    {
        $project = Project::factory()->create(['status' => 'EN_EJECUCION']);

        $response = $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", [
                'paymentType' => 'FINAL',
                'amount'      => 1000,
            ]);

        $response->assertStatus(422);
        $this->assertEquals('EN_EJECUCION', $project->fresh()->status);
    }

    public function test_pay_final_rejects_reopening_completed_project(): void
    {
        // Escenario exacto de la auditoría: pagar sobre un proyecto ya
        // COMPLETADO_PAGADO no debe poder reabrirlo.
        $project = Project::factory()->create(['status' => 'COMPLETADO_PAGADO']);

        $response = $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", [
                'paymentType' => 'ADVANCE',
                'amount'      => 500,
            ]);

        $response->assertStatus(422);
        $this->assertEquals('COMPLETADO_PAGADO', $project->fresh()->status);
    }

    public function test_report_finished_rejects_project_not_in_en_ejecucion(): void
    {
        $project = Project::factory()->create(['status' => 'CREADO']);

        $response = $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$project->id}/report-finished");

        $response->assertStatus(422);
        $this->assertEquals('CREADO', $project->fresh()->status);
    }

    public function test_verify_completion_rejects_project_not_in_verificando_finalizacion(): void
    {
        $project = Project::factory()->create(['status' => 'EN_EJECUCION']);

        $response = $this->actingAs($this->cierre)
            ->postJson("/api/projects/{$project->id}/verify-completion", [
                'qualityVerified' => true,
            ]);

        $response->assertStatus(422);
        $this->assertEquals('EN_EJECUCION', $project->fresh()->status);
    }
}
