<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\PaymentOrder;
use App\Models\PaymentSignatureStep;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F4 Bloque C: la cadena configurada se firma sola en cada acción real del circuito y bloquea el pago si falta una firma. */
class PaymentSignatureIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $procura;
    private User $presidencia;
    private User $finanzas;
    private Contractor $contractor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->procura = User::factory()->create(['role' => 'PROCURA']);
        $this->presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);
        $this->finanzas = User::factory()->create(['role' => 'FINANZAS']);
        $this->contractor = Contractor::factory()->create();

        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 1, 'role' => 'PROCURA', 'label' => 'Elaboración']);
        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 2, 'role' => 'PRESIDENCIA', 'label' => 'Aprobación']);
        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 3, 'role' => 'FINANZAS', 'label' => 'Pago']);
    }

    public function test_advance_order_is_auto_signed_through_the_real_flow_and_pay_completes_the_chain(): void
    {
        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id, 'contractor_code' => $this->contractor->code,
            'total_cost' => 10000, 'negotiated_advance_percent' => 30,
        ]);

        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code, 'proposalId' => $proposal->id,
        ])->assertStatus(200);

        $order = PaymentOrder::where('project_id', $project->id)->firstOrFail();
        $this->assertSame(1, $order->signatures()->count());
        $this->assertSame('EN_FIRMA', $order->status);

        $this->actingAs($this->presidencia)->postJson("/api/projects/{$project->id}/award-approval")->assertStatus(200);
        $this->assertSame(2, $order->fresh()->signatures()->count());

        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/send-to-finance")->assertStatus(200);
        // Send-to-finance no coincide con ningún paso pendiente (falta FINANZAS): no firma nada extra.
        $this->assertSame(2, $order->fresh()->signatures()->count());
        $this->assertSame('EN_FIRMA', $order->fresh()->status);

        ProjectDocument::create(['project_id' => $project->id, 'document_type' => 'COMPROBANTE_ANTICIPO', 'original_name' => 'p.pdf', 'stored_path' => 'x/p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'version_number' => 1]);

        $this->actingAs($this->finanzas)->postJson("/api/projects/{$project->id}/payments", [
            'paymentType' => 'ADVANCE', 'amount' => 3000.00, 'paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 3000.00,
        ])->assertStatus(200);

        $this->assertSame(3, $order->fresh()->signatures()->count());
        $this->assertSame('PAGADA', $order->fresh()->status);
    }

    public function test_pay_is_blocked_when_the_chain_is_incomplete(): void
    {
        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id, 'contractor_code' => $this->contractor->code,
            'total_cost' => 10000, 'negotiated_advance_percent' => 30,
        ]);
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code, 'proposalId' => $proposal->id,
        ]);
        // Presidencia NO aprueba con firma real de otro flujo alterno: forzamos el estado sin pasar por award-approval.
        $project->update(['status' => 'CONTRATADO']);
        ProjectDocument::create(['project_id' => $project->id, 'document_type' => 'COMPROBANTE_ANTICIPO', 'original_name' => 'p.pdf', 'stored_path' => 'x/p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'version_number' => 1]);

        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", ['paymentType' => 'ADVANCE', 'amount' => 3000.00, 'paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 3000.00])
            ->assertStatus(422)
            ->assertJsonValidationErrors('signature');

        $this->assertDatabaseMissing('project_payments', ['project_id' => $project->id]);
    }

    /**
     * Decisión del usuario 2026-09-29: la aprobación de Presidencia y la
     * firma son cosas TOTALMENTE distintas — aprobar nunca exige ni bloquea
     * por firma, sea quien sea el actor. Solo Finanzas (al pagar) bloquea.
     */
    public function test_award_approval_never_blocks_on_signature_even_for_a_mismatched_actor(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id, 'contractor_code' => $this->contractor->code,
            'total_cost' => 10000, 'negotiated_advance_percent' => 30,
        ]);
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code, 'proposalId' => $proposal->id,
        ])->assertStatus(200);

        // El paso 1 (PROCURA) ya quedó firmado; el 2 (PRESIDENCIA) está pendiente y
        // SUPERADMIN no coincide con él — igual debe poder aprobar sin bloqueo.
        $this->actingAs($superadmin)->postJson("/api/projects/{$project->id}/award-approval")->assertStatus(200);

        $this->assertSame('APROBADO_PRESIDENCIA', $project->fresh()->status);
        // El intento de firma es mejor esfuerzo: no coincidió, así que solo queda firmado el paso 1 (PROCURA).
        $this->assertSame(1, PaymentOrder::where('project_id', $project->id)->firstOrFail()->signatures()->count());
    }

    /** El bloqueo real de "no saltarse el paso de otro rol" vive en pay(), no en award-approval. */
    public function test_pay_blocks_a_superadmin_that_does_not_match_the_configured_signer(): void
    {
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id, 'contractor_code' => $this->contractor->code,
            'total_cost' => 10000, 'negotiated_advance_percent' => 30,
        ]);
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code, 'proposalId' => $proposal->id,
        ]);
        $this->actingAs($this->presidencia)->postJson("/api/projects/{$project->id}/award-approval");
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/send-to-finance");
        ProjectDocument::create(['project_id' => $project->id, 'document_type' => 'COMPROBANTE_ANTICIPO', 'original_name' => 'p.pdf', 'stored_path' => 'x/p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'version_number' => 1]);

        $response = $this->actingAs($superadmin)->postJson("/api/projects/{$project->id}/payments", [
            'paymentType' => 'ADVANCE', 'amount' => 3000.00, 'paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 3000.00,
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('signature');
        $this->assertDatabaseMissing('project_payments', ['project_id' => $project->id]);
    }

    /** El paso opcional (is_required=false) queda registrado si se firma en su turno, pero nunca bloquea a los pasos obligatorios siguientes. */
    public function test_optional_step_does_not_block_the_chain(): void
    {
        PaymentSignatureStep::query()->delete();
        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 1, 'role' => 'ANALISTA', 'label' => 'Revisión opcional', 'is_required' => false]);
        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 2, 'role' => 'PRESIDENCIA', 'label' => 'Aprobación', 'is_required' => true]);

        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id, 'contractor_code' => $this->contractor->code,
            'total_cost' => 10000, 'negotiated_advance_percent' => 30,
        ]);
        // Procura no coincide con el paso opcional (ANALISTA): select-contractor no debe bloquearse.
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code, 'proposalId' => $proposal->id,
        ])->assertStatus(200);

        // Presidencia puede aprobar aunque el paso opcional nunca se firmó.
        $this->actingAs($this->presidencia)->postJson("/api/projects/{$project->id}/award-approval")->assertStatus(200);
    }

    public function test_signature_hash_is_traceable_and_order_endpoint_exposes_the_line(): void
    {
        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id, 'contractor_code' => $this->contractor->code,
            'total_cost' => 10000, 'negotiated_advance_percent' => 30,
        ]);
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code, 'proposalId' => $proposal->id,
        ]);
        $order = PaymentOrder::where('project_id', $project->id)->firstOrFail();

        $response = $this->actingAs($this->presidencia)->getJson("/api/payment-orders/{$order->id}")->assertStatus(200);

        $response->assertJsonPath('data.integrityValid', true)
            ->assertJsonPath('data.signatureLine.0.status', 'FIRMADO')
            ->assertJsonPath('data.signatureLine.1.status', 'PROXIMO')
            ->assertJsonPath('data.canSign', true);

        $this->assertNotEmpty($response->json('data.signatures.0.signedAt' ?? null) ?? $order->signatures()->first()->signature_hash);
    }

    public function test_only_the_configured_user_can_sign_via_the_endpoint(): void
    {
        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id, 'contractor_code' => $this->contractor->code,
            'total_cost' => 10000, 'negotiated_advance_percent' => 30,
        ]);
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code, 'proposalId' => $proposal->id,
        ]);
        $order = PaymentOrder::where('project_id', $project->id)->firstOrFail();

        $this->actingAs($this->finanzas)->postJson("/api/payment-orders/{$order->id}/sign")->assertStatus(422);
        $this->actingAs($this->presidencia)->postJson("/api/payment-orders/{$order->id}/sign")->assertStatus(201);
    }
}
