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
            'paymentType' => 'ADVANCE', 'amount' => 3000.00,
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
            ->postJson("/api/projects/{$project->id}/payments", ['paymentType' => 'ADVANCE', 'amount' => 3000.00])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseMissing('project_payments', ['project_id' => $project->id]);
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
