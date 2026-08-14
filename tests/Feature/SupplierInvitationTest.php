<?php

namespace Tests\Feature;

use App\Models\AppSetting;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\SupplierInvitation;
use App\Models\SupplierMaterialProposal;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->project = Project::factory()->create();
        // Create materials for the project so the public view returns them
        $this->project->materials()->create([
            'id'                  => 'MAT-TEST-1',
            'name'                => 'Material Test',
            'quantity'            => 100,
            'unit'                => 'kg',
            'estimated_unit_price' => 10.00,
        ]);
    }

    private function authHeaders(): array
    {
        return ['Authorization' => 'Bearer ' . $this->user->createToken('test')->plainTextToken];
    }

    public function test_create_invitation(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/supplier-invitations', [
                'project_id'      => $this->project->id,
                'supplierName'    => 'Proveedor Test',
                'supplierCompany' => 'Compañía Test S.A.',
                'supplierContact' => 'proveedor@test.com',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'token', 'projectTitle', 'supplierName', 'supplierContact', 'createdAt', 'expiresAt',
        ]);
        $this->assertTrue(Str::isUuid($response->json('token')));
        $this->assertEquals('Proveedor Test', $response->json('supplierName'));
    }

    public function test_create_invitation_is_recorded_in_audit_log_with_project(): void
    {
        // Tiene $project asociado (parte del flujo de una obra específica,
        // no una operación de panel admin) — pasa por AuditLog::record(),
        // no ConfigAuditLog, para que Presidencia lo vea junto al resto del
        // flujo regular.
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/supplier-invitations', [
                'project_id'      => $this->project->id,
                'supplierName'    => 'Proveedor Test',
                'supplierContact' => 'proveedor@test.com',
            ])
            ->assertStatus(201);

        $this->assertDatabaseHas('audit_logs', [
            'project_id' => $this->project->id,
            'action' => 'Envio de invitacion a proveedor',
        ]);
    }

    public function test_create_invitation_replaces_previous_active(): void
    {
        // Create first invitation
        $first = SupplierInvitation::factory()->create([
            'project_id'       => $this->project->id,
            'supplier_contact' => 'replaced@test.com',
            'used_at'          => null,
            'replaced_by'      => null,
        ]);

        // Second invitation for same project+contact should replace the first
        $response = $this->withHeaders($this->authHeaders())
            ->postJson('/api/supplier-invitations', [
                'project_id'      => $this->project->id,
                'supplierName'    => 'Nuevo Proveedor',
                'supplierContact' => 'replaced@test.com',
            ]);

        $response->assertStatus(201);
        $newToken = $response->json('token');

        // First invitation should be marked as replaced
        $this->assertDatabaseHas('supplier_invitations', [
            'id'           => $first->id,
            'replaced_by'  => $newToken,
        ]);

        // First invitation is no longer valid
        $this->assertFalse($first->fresh()->isValid());
    }

    public function test_view_invitation_publicly(): void
    {
        $invitation = SupplierInvitation::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->getJson("/api/public/invitations/{$invitation->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'supplierName', 'supplierCompany', 'supplierContact',
            'project' => ['id', 'title', 'location', 'type', 'materials'],
        ]);
        $response->assertJson([
            'supplierName' => $invitation->supplier_name,
            'project'      => ['id' => $this->project->id],
        ]);
        $this->assertCount(1, $response->json('project.materials'));
    }

    public function test_view_expired_invitation_returns_404(): void
    {
        $invitation = SupplierInvitation::factory()->used()->create();

        $response = $this->getJson("/api/public/invitations/{$invitation->id}");
        $response->assertStatus(404);
        $response->assertJson(['message' => 'Enlace no valido o expirado.']);
    }

    public function test_create_invitation_sets_default_expiration(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/supplier-invitations', [
                'project_id'      => $this->project->id,
                'supplierName'    => 'Proveedor Test',
                'supplierContact' => 'proveedor@test.com',
            ])->assertStatus(201);

        $invitation = SupplierInvitation::first();
        $this->assertNotNull($invitation->expires_at);
        $this->assertTrue($invitation->expires_at->isFuture());
    }

    public function test_create_invitation_expiration_respects_configured_validity_days(): void
    {
        AppSetting::where('key', 'invitacion_proveedor_vigencia_dias')->update(['value' => '3']);
        SettingsService::forget();

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/supplier-invitations', [
                'project_id'      => $this->project->id,
                'supplierName'    => 'Proveedor Test',
                'supplierContact' => 'proveedor@test.com',
            ])->assertStatus(201);

        $invitation = SupplierInvitation::first();
        $this->assertEqualsWithDelta(
            now()->addDays(3)->timestamp,
            $invitation->expires_at->timestamp,
            5,
        );
    }

    public function test_view_time_expired_invitation_returns_404(): void
    {
        $invitation = SupplierInvitation::factory()->expired()->create();

        $response = $this->getJson("/api/public/invitations/{$invitation->id}");
        $response->assertStatus(404);
        $response->assertJson(['message' => 'Enlace no valido o expirado.']);
    }

    public function test_submit_proposal_with_time_expired_token_returns_404(): void
    {
        $invitation = SupplierInvitation::factory()->expired()->create();

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [],
        ]);

        $response->assertStatus(404);
    }

    public function test_view_replaced_invitation_returns_404(): void
    {
        $invitation = SupplierInvitation::factory()->replaced()->create();

        $response = $this->getJson("/api/public/invitations/{$invitation->id}");
        $response->assertStatus(404);
    }

    public function test_view_nonexistent_invitation_returns_404(): void
    {
        $response = $this->getJson('/api/public/invitations/non-existent-uuid');
        $response->assertStatus(404);
    }

    public function test_submit_proposal_using_invitation(): void
    {
        $invitation = SupplierInvitation::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [
                [
                    'materialName' => 'Cemento',
                    'quantity'     => 100,
                    'unit'         => 'kg',
                    'unitPrice'    => 12.50,
                    'totalPrice'   => 1250.00,
                ],
                [
                    'materialName' => 'Acero',
                    'quantity'     => 50,
                    'unit'         => 'm',
                    'unitPrice'    => 25.00,
                    'totalPrice'   => 1250.00,
                ],
            ],
            'estimatedDays' => 30,
            'durationUnit'  => 'dias',
            'generalNotes'  => 'Entrega en 30 días hábiles',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'id', 'projectId', 'supplierName', 'supplierCompany',
            'items', 'estimatedDays', 'durationUnit', 'submittedAt',
        ]);

        // Invitation should be marked as used
        $this->assertNotNull($invitation->fresh()->used_at);

        // Supplier material proposal created
        $this->assertDatabaseHas('supplier_material_proposals', [
            'invitation_token' => $invitation->id,
            'project_id'       => $this->project->id,
        ]);
    }

    public function test_submit_proposal_rejects_advance_percent_above_sanity_ceiling(): void
    {
        // El link público NO respeta el máximo configurable de CONFIG APP —
        // el proveedor externo cotiza su condición real sin conocer la
        // política interna. Solo hay una cota de sanidad fija (100%).
        $invitation = SupplierInvitation::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [
                ['materialName' => 'Cemento', 'quantity' => 100, 'unit' => 'kg', 'unitPrice' => 12.50, 'totalPrice' => 1250.00],
            ],
            'advancePercent' => 150,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('advancePercent');
    }

    public function test_submit_proposal_accepts_advance_percent_above_configured_max(): void
    {
        // Aunque CONFIG APP tenga el tope en 20%, el link público lo ignora —
        // solo Analistas/Procura ven la alerta al evaluar la oferta.
        AppSetting::where('key', 'anticipo_maximo_porcentaje')->update(['value' => '20']);
        SettingsService::forget();

        $invitation = SupplierInvitation::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [
                ['materialName' => 'Cemento', 'quantity' => 100, 'unit' => 'kg', 'unitPrice' => 12.50, 'totalPrice' => 1250.00],
            ],
            'advancePercent' => 30,
        ]);

        $response->assertStatus(201);
    }

    public function test_submit_proposal_with_used_token_returns_404(): void
    {
        $invitation = SupplierInvitation::factory()->used()->create();

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'items' => [
                ['materialName' => 'Test', 'quantity' => 1, 'unit' => 'kg', 'unitPrice' => 10, 'totalPrice' => 10],
            ],
        ]);

        $response->assertStatus(404);
    }

    public function test_list_supplier_proposals(): void
    {
        SupplierMaterialProposal::factory()->count(3)->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/supplier-material-proposals');

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));

        // Filter by project
        $response = $this->withHeaders($this->authHeaders())
            ->getJson('/api/supplier-material-proposals?project_id=' . $this->project->id);

        $response->assertStatus(200);
        $this->assertCount(3, $response->json('data'));
    }

    public function test_import_supplier_proposals_as_project_proposals(): void
    {
        $contractor = Contractor::factory()->create([
            'name'    => 'Proveedor Test',
            'contact' => 'proveedor@test.com',
        ]);

        // First invitation + proposal
        $invitation1 = SupplierInvitation::factory()->create([
            'project_id'       => $this->project->id,
            'supplier_name'    => $contractor->name,
            'supplier_contact' => $contractor->contact,
        ]);
        SupplierMaterialProposal::factory()->create([
            'project_id'       => $this->project->id,
            'invitation_token' => $invitation1->id,
            'supplier_name'    => $contractor->name,
            'supplier_contact' => $contractor->contact,
            'items'            => [
                ['name' => 'Material 1', 'quantity' => 10, 'unitPrice' => 100, 'totalPrice' => 1000],
            ],
            'duration_unit' => 'dias',
            'estimated_days' => 45,
        ]);

        // ANALISTA imports proposals
        $analista = User::factory()->create(['role' => 'ANALISTA']);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $analista->createToken('test')->plainTextToken,
        ])->postJson("/api/projects/{$this->project->id}/import-supplier-proposals");

        $response->assertStatus(200);
        $response->assertJson([
            'imported' => 1,
            'skipped'  => 0,
            'errors'   => [],
        ]);

        // The proposal should now appear as a ProjectProposal
        $this->assertCount(1, $this->project->fresh()->proposals);
    }

    public function test_import_no_supplier_proposals_returns_empty(): void
    {
        $project = Project::factory()->create();

        $analista = User::factory()->create(['role' => 'ANALISTA']);
        $response = $this->withHeaders([
            'Authorization' => 'Bearer ' . $analista->createToken('test')->plainTextToken,
        ])->postJson("/api/projects/{$project->id}/import-supplier-proposals");

        $response->assertStatus(200);
        $response->assertJson([
            'imported' => 0,
            'skipped'  => 0,
            'errors'   => [],
        ]);
    }
}
