<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\PaymentOrder;
use App\Models\Project;
use App\Models\ProjectDocument;
use App\Models\ProjectProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F4 Bloque B punto 9: la orden nace/se anula con el circuito real de adjudicación y bloquea el pago. */
class PaymentOrderIntegrationTest extends TestCase
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
    }

    private function projectReadyToSelect(): array
    {
        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $this->contractor->code,
            'total_cost' => 10000.00,
            'negotiated_advance_percent' => 30,
        ]);

        return [$project, $proposal];
    }

    public function test_selecting_a_contractor_generates_the_advance_order(): void
    {
        [$project, $proposal] = $this->projectReadyToSelect();

        $this->actingAs($this->procura)
            ->postJson("/api/projects/{$project->id}/select-contractor", [
                'contractorCode' => $this->contractor->code,
                'proposalId' => $proposal->id,
            ])
            ->assertStatus(200);

        $this->assertDatabaseHas('payment_orders', [
            'project_id' => $project->id,
            'payment_type' => 'ADVANCE',
            'amount' => 3000.00,
            'status' => 'EN_FIRMA',
            'current_key' => "{$project->id}:ADVANCE",
        ]);
    }

    public function test_rejecting_the_award_voids_the_order_and_reselecting_regenerates_it(): void
    {
        [$project, $proposal] = $this->projectReadyToSelect();
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code,
            'proposalId' => $proposal->id,
        ])->assertStatus(200);

        $firstOrderId = PaymentOrder::where('project_id', $project->id)->value('id');

        $this->actingAs($this->presidencia)
            ->postJson("/api/projects/{$project->id}/award-rejection", ['reason' => 'Monto excesivo'])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'COMPARATIVA_ENVIADA');

        $this->assertDatabaseHas('payment_orders', ['id' => $firstOrderId, 'status' => 'ANULADA', 'current_key' => null]);

        $newProposal = ProjectProposal::factory()->create([
            'project_id' => $project->id,
            'contractor_code' => $this->contractor->code,
            'total_cost' => 10000.00,
            'negotiated_advance_percent' => 30,
        ]);
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code,
            'proposalId' => $newProposal->id,
        ])->assertStatus(200);

        $this->assertDatabaseHas('payment_orders', [
            'project_id' => $project->id,
            'status' => 'EN_FIRMA',
            'current_key' => "{$project->id}:ADVANCE",
        ]);
        $this->assertSame(2, PaymentOrder::where('project_id', $project->id)->count());
    }

    public function test_pay_rejects_amount_that_does_not_match_the_order(): void
    {
        [$project, $proposal] = $this->projectReadyToSelect();
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code,
            'proposalId' => $proposal->id,
        ]);
        $this->actingAs($this->presidencia)->postJson("/api/projects/{$project->id}/award-approval");
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/send-to-finance");
        ProjectDocument::create(['project_id' => $project->id, 'document_type' => 'COMPROBANTE_ANTICIPO', 'original_name' => 'p.pdf', 'stored_path' => 'x/p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'version_number' => 1]);

        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", ['paymentType' => 'ADVANCE', 'amount' => 999.99, 'paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 999.99])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');

        $this->assertDatabaseMissing('project_payments', ['project_id' => $project->id]);
    }

    public function test_paying_the_correct_amount_links_and_settles_the_order(): void
    {
        [$project, $proposal] = $this->projectReadyToSelect();
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code,
            'proposalId' => $proposal->id,
        ]);
        $this->actingAs($this->presidencia)->postJson("/api/projects/{$project->id}/award-approval");
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/send-to-finance");
        ProjectDocument::create(['project_id' => $project->id, 'document_type' => 'COMPROBANTE_ANTICIPO', 'original_name' => 'p.pdf', 'stored_path' => 'x/p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'version_number' => 1]);

        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", ['paymentType' => 'ADVANCE', 'amount' => 3000.00, 'paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 3000.00])
            ->assertStatus(200)
            ->assertJsonPath('data.status', 'EN_EJECUCION');

        $order = PaymentOrder::where('project_id', $project->id)->first();
        $this->assertSame('PAGADA', $order->status);
        $this->assertDatabaseHas('project_payments', ['project_id' => $project->id, 'payment_order_id' => $order->id, 'amount' => 3000.00]);
    }

    public function test_project_resource_exposes_the_current_advance_order(): void
    {
        [$project, $proposal] = $this->projectReadyToSelect();
        $this->actingAs($this->procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $this->contractor->code,
            'proposalId' => $proposal->id,
        ]);

        $response = $this->actingAs($this->finanzas)->getJson("/api/projects/{$project->id}")->assertStatus(200);

        $response->assertJsonPath('data.paymentOrders.advance.amount', 3000)
            ->assertJsonPath('data.paymentOrders.advance.status', 'EN_FIRMA')
            ->assertJsonPath('data.paymentOrders.final', null);
    }

    public function test_pay_without_any_order_is_rejected(): void
    {
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        ProjectDocument::create(['project_id' => $project->id, 'document_type' => 'COMPROBANTE_ANTICIPO', 'original_name' => 'p.pdf', 'stored_path' => 'x/p.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1, 'version_number' => 1]);

        $this->actingAs($this->finanzas)
            ->postJson("/api/projects/{$project->id}/payments", ['paymentType' => 'ADVANCE', 'amount' => 100, 'paymentMode' => 'QUOTE_CURRENCY', 'paidAmount' => 100])
            ->assertStatus(422)
            ->assertJsonValidationErrors('amount');
    }
}
