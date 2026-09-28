<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Contractor;
use App\Models\PaymentOrder;
use App\Models\Project;
use App\Models\ProjectClosureReport;
use App\Models\ProjectProposal;
use App\Models\User;
use App\Services\PaymentOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentOrderServiceTest extends TestCase
{
    use RefreshDatabase;

    private function projectWithProposal(array $proposalOverrides = []): Project
    {
        $contractor = Contractor::factory()->create(['code' => 'CON-ORD']);
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $proposal = ProjectProposal::factory()->for($project)->create([
            'contractor_code' => $contractor->code,
            'total_cost' => 10000,
            'negotiated_advance_percent' => 30,
            ...$proposalOverrides,
        ]);
        $project->update(['selected_contractor_code' => $contractor->code, 'selected_proposal_id' => $proposal->id]);

        return $project->fresh();
    }

    public function test_generate_advance_order_computes_amount_from_proposal(): void
    {
        $project = $this->projectWithProposal();

        $order = app(PaymentOrderService::class)->generate($project, PaymentOrder::TYPE_ADVANCE);

        $this->assertSame(3000.0, $order->amount);
        $this->assertSame(PaymentOrder::STATUS_EN_FIRMA, $order->status);
        $this->assertSame("{$project->id}:ADVANCE", $order->current_key);
        $this->assertSame(1, $order->number);
        $this->assertNotEmpty($order->content_hash);
    }

    public function test_generate_final_order_uses_finiquito_amount(): void
    {
        $project = $this->projectWithProposal();
        ProjectClosureReport::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'project_id' => $project->id,
            'status' => ProjectClosureReport::STATUS_AUDIT_APPROVED,
            'finiquito_amount' => 4500,
        ]);

        $order = app(PaymentOrderService::class)->generate($project->fresh(), PaymentOrder::TYPE_FINAL);

        $this->assertSame(4500.0, $order->amount);
    }

    public function test_generate_without_finiquito_amount_fails(): void
    {
        $project = $this->projectWithProposal();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(PaymentOrderService::class)->generate($project, PaymentOrder::TYPE_FINAL);
    }

    public function test_regenerating_voids_the_previous_order(): void
    {
        $project = $this->projectWithProposal();
        $service = app(PaymentOrderService::class);

        $first = $service->generate($project, PaymentOrder::TYPE_ADVANCE);
        $second = $service->generate($project->fresh(), PaymentOrder::TYPE_ADVANCE);

        $this->assertSame(PaymentOrder::STATUS_ANULADA, $first->fresh()->status);
        $this->assertNull($first->fresh()->current_key);
        $this->assertSame(PaymentOrder::STATUS_EN_FIRMA, $second->status);
        $this->assertSame(2, $second->number);
    }

    public function test_void_current_does_nothing_when_no_order_exists(): void
    {
        $project = $this->projectWithProposal();

        app(PaymentOrderService::class)->voidCurrent($project, PaymentOrder::TYPE_ADVANCE, 'Rechazo de adjudicación');

        $this->assertSame(0, PaymentOrder::count());
    }

    public function test_paid_order_cannot_be_voided(): void
    {
        $project = $this->projectWithProposal();
        $order = app(PaymentOrderService::class)->generate($project, PaymentOrder::TYPE_ADVANCE);
        $order->update(['status' => PaymentOrder::STATUS_PAGADA]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(PaymentOrderService::class)->void($order, 'intento invalido');
    }

    public function test_assert_ready_to_pay_rejects_amount_mismatch(): void
    {
        $project = $this->projectWithProposal();
        app(PaymentOrderService::class)->generate($project, PaymentOrder::TYPE_ADVANCE);

        $this->expectException(ValidationException::class);
        app(PaymentOrderService::class)->assertReadyToPay($project, PaymentOrder::TYPE_ADVANCE, 999.99);
    }

    public function test_assert_ready_to_pay_rejects_when_no_order(): void
    {
        $project = $this->projectWithProposal();

        $this->expectException(ValidationException::class);
        app(PaymentOrderService::class)->assertReadyToPay($project, PaymentOrder::TYPE_ADVANCE, 3000);
    }

    public function test_assert_ready_to_pay_accepts_matching_amount(): void
    {
        $project = $this->projectWithProposal();
        $order = app(PaymentOrderService::class)->generate($project, PaymentOrder::TYPE_ADVANCE);

        $result = app(PaymentOrderService::class)->assertReadyToPay($project, PaymentOrder::TYPE_ADVANCE, 3000.00);

        $this->assertTrue($result->is($order));
    }

    public function test_elaborated_by_resolves_the_analyst_who_submitted_the_comparative(): void
    {
        $analyst = User::factory()->create(['role' => 'ANALISTA']);
        $project = $this->projectWithProposal();
        AuditLog::record($project, 'ANALISTA', 'Carga de cuadro comparativo', 'Comparativa enviada.');
        // Simula el actor real de esa entrada (AuditLog::record usa el usuario autenticado).
        AuditLog::where('project_id', $project->id)->where('action', 'Carga de cuadro comparativo')->update(['user_id' => $analyst->id]);

        $order = app(PaymentOrderService::class)->generate($project, PaymentOrder::TYPE_ADVANCE);

        $this->assertSame($analyst->id, $order->elaborated_by);
    }
}
