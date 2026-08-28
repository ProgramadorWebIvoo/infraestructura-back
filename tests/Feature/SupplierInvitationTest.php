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

    public function test_submit_proposal_with_labor_cost(): void
    {
        $invitation = SupplierInvitation::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'quoteCurrency' => 'USD',
            'items' => [
                ['materialName' => 'Cemento', 'quantity' => 100, 'unit' => 'kg', 'unitPrice' => 12.50, 'totalPrice' => 1250.00, 'conditionStatus' => 'new', 'warrantyDescription' => 'N/A'],
            ],
            'laborCost' => 350.75,
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('laborCost', 350.75);
        $this->assertDatabaseHas('supplier_material_proposals', ['id' => $response->json('id'), 'labor_cost' => 350.75]);
    }

    public function test_submit_proposal_without_labor_cost_defaults_to_null(): void
    {
        $invitation = SupplierInvitation::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'quoteCurrency' => 'USD',
            'items' => [
                ['materialName' => 'Cemento', 'quantity' => 100, 'unit' => 'kg', 'unitPrice' => 12.50, 'totalPrice' => 1250.00, 'conditionStatus' => 'new', 'warrantyDescription' => 'N/A'],
            ],
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('laborCost', null);
    }

    public function test_submit_proposal_using_invitation(): void
    {
        $invitation = SupplierInvitation::factory()->create([
            'project_id' => $this->project->id,
        ]);

        $response = $this->postJson("/api/public/invitations/{$invitation->id}/proposal", [
            'quoteCurrency' => 'USD',
            'items' => [
                [
                    'materialName' => 'Cemento',
                    'quantity'     => 100,
                    'unit'         => 'kg',
                    'unitPrice'    => 12.50,
                    'totalPrice'   => 1250.00,
                    'conditionStatus' => 'new',
                    'warrantyDescription' => 'Garantía de fábrica',
                ],
                [
                    'materialName' => 'Acero',
                    'quantity'     => 50,
                    'unit'         => 'm',
                    'unitPrice'    => 25.00,
                    'totalPrice'   => 1250.00,
                    'conditionStatus' => 'new',
                    'warrantyDescription' => 'Garantía de fábrica',
                ],
            ],
            'estimatedDays' => 30,
            'durationUnit'  => 'dias',
            'generalNotes'  => 'Entrega en 30 días hábiles',
        ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'id', 'projectId', 'supplierName', 'supplierCompany',
            'quoteCurrency', 'items', 'estimatedDays', 'durationUnit', 'submittedAt',
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
            'quoteCurrency' => 'USD',
            'items' => [
                ['materialName' => 'Cemento', 'quantity' => 100, 'unit' => 'kg', 'unitPrice' => 12.50, 'totalPrice' => 1250.00, 'conditionStatus' => 'new', 'warrantyDescription' => 'Garantía de fábrica'],
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
            'name'  => 'Proveedor Test',
            'email' => 'proveedor@test.com',
        ]);

        // First invitation + proposal
        $invitation1 = SupplierInvitation::factory()->create([
            'project_id'       => $this->project->id,
            'supplier_name'    => $contractor->name,
            'supplier_contact' => $contractor->email,
        ]);
        SupplierMaterialProposal::factory()->create([
            'project_id'       => $this->project->id,
            'invitation_token' => $invitation1->id,
            'supplier_name'    => $contractor->name,
            'supplier_contact' => $contractor->email,
            'items'            => [
                [
                    'materialName'        => 'Material 1',
                    'quantity'            => 10,
                    'unitPrice'           => 100,
                    'totalPrice'          => 1000,
                    'conditionStatus'     => 'new',
                    'warrantyDescription' => 'Garantía de fábrica 1 año',
                    'warrantyValue'       => 12,
                    'warrantyUnit'        => 'meses',
                    'imagePath'           => "supplier-proposal-images/{$invitation1->id}/foto.jpg",
                ],
            ],
            'duration_unit'  => 'dias',
            'estimated_days' => 45,
            'quote_currency' => 'EUR',
            'labor_cost'     => 250.50,
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

        // The proposal should now appear as a ProjectProposal, preserving the
        // per-line enriched fields (condition/warranty/image) untouched and
        // carrying over the header-level currency + labor cost.
        $imported = $this->project->fresh()->proposals->first();
        $this->assertNotNull($imported);
        $this->assertSame('EUR', $imported->quote_currency);
        $this->assertEquals(250.50, $imported->labor_cost);
        $this->assertSame('new', $imported->material_items[0]['conditionStatus']);
        $this->assertSame('Garantía de fábrica 1 año', $imported->material_items[0]['warrantyDescription']);
        $this->assertSame(12, $imported->material_items[0]['warrantyValue']);
        $this->assertSame('meses', $imported->material_items[0]['warrantyUnit']);
        $this->assertStringContainsString('foto.jpg', $imported->material_items[0]['imagePath']);
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

    public function test_latest_returns_null_when_no_invitation_exists(): void
    {
        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/supplier-invitations/latest?project_id={$this->project->id}&supplierContact=nadie@test.com");

        $response->assertStatus(200);
        $response->assertJsonPath('data', null);
    }

    public function test_latest_returns_the_active_invitation(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/supplier-invitations', [
                'project_id'      => $this->project->id,
                'supplierName'    => 'Proveedor Test',
                'supplierContact' => 'proveedor@test.com',
            ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/supplier-invitations/latest?project_id={$this->project->id}&supplierContact=proveedor@test.com");

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.token'));
        $response->assertJsonPath('data.supplierContact', 'proveedor@test.com');
        $response->assertJsonPath('data.status', 'active');
    }

    public function test_latest_reports_used_status_instead_of_null(): void
    {
        // Antes devolvía null para un enlace usado (mismo shape que "nunca
        // existió") — el modal no podía distinguir "genera uno nuevo" de
        // "el proveedor YA envió su propuesta con este enlace", y seguía
        // mostrando el enlace viejo como vigente si lo tenía en memoria.
        $invitation = SupplierInvitation::factory()->used()->create([
            'project_id'       => $this->project->id,
            'supplier_contact' => 'usado@test.com',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/supplier-invitations/latest?project_id={$this->project->id}&supplierContact=usado@test.com");

        $response->assertStatus(200);
        $response->assertJsonPath('data.token', (string) $invitation->id);
        $response->assertJsonPath('data.status', 'used');
    }

    public function test_latest_reports_expired_status_instead_of_null(): void
    {
        $invitation = SupplierInvitation::factory()->expired()->create([
            'project_id'       => $this->project->id,
            'supplier_contact' => 'expirado@test.com',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/supplier-invitations/latest?project_id={$this->project->id}&supplierContact=expirado@test.com");

        $response->assertStatus(200);
        $response->assertJsonPath('data.token', (string) $invitation->id);
        $response->assertJsonPath('data.status', 'expired');
    }

    public function test_latest_reports_replaced_status(): void
    {
        $invitation = SupplierInvitation::factory()->replaced()->create([
            'project_id'       => $this->project->id,
            'supplier_contact' => 'reemplazado@test.com',
        ]);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/supplier-invitations/latest?project_id={$this->project->id}&supplierContact=reemplazado@test.com");

        $response->assertStatus(200);
        $response->assertJsonPath('data.token', (string) $invitation->id);
        $response->assertJsonPath('data.status', 'replaced');
    }

    public function test_latest_returns_the_newest_after_regenerating(): void
    {
        $this->withHeaders($this->authHeaders())
            ->postJson('/api/supplier-invitations', [
                'project_id'      => $this->project->id,
                'supplierName'    => 'Proveedor Test',
                'supplierContact' => 'proveedor@test.com',
            ])->assertStatus(201);

        $second = $this->withHeaders($this->authHeaders())
            ->postJson('/api/supplier-invitations', [
                'project_id'      => $this->project->id,
                'supplierName'    => 'Proveedor Test',
                'supplierContact' => 'proveedor@test.com',
            ])->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders())
            ->getJson("/api/supplier-invitations/latest?project_id={$this->project->id}&supplierContact=proveedor@test.com");

        $response->assertStatus(200);
        $response->assertJsonPath('data.token', $second->json('token'));
    }
}
