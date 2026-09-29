<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\PaymentOrder;
use App\Models\PaymentSignatureStep;
use App\Models\Project;
use App\Models\ProjectProposal;
use App\Models\User;
use App\Services\PaymentOrderService;
use App\Services\PaymentSignatureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PaymentSignatureServiceTest extends TestCase
{
    use RefreshDatabase;

    private function orderWithSteps(): PaymentOrder
    {
        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 1, 'role' => 'PROCURA', 'label' => 'Elaboración']);
        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 2, 'role' => 'PRESIDENCIA', 'label' => 'Aprobación']);
        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 3, 'role' => 'FINANZAS', 'label' => 'Pago']);

        $contractor = Contractor::factory()->create(['code' => 'CON-SIG']);
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $proposal = ProjectProposal::factory()->for($project)->create([
            'contractor_code' => $contractor->code, 'total_cost' => 10000, 'negotiated_advance_percent' => 30,
        ]);
        $project->update(['selected_contractor_code' => $contractor->code, 'selected_proposal_id' => $proposal->id]);

        return app(PaymentOrderService::class)->generate($project->fresh(), PaymentOrder::TYPE_ADVANCE);
    }

    public function test_signs_in_strict_order_and_marks_firmada_when_complete(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $procura = User::factory()->create(['role' => 'PROCURA']);
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);
        $finanzas = User::factory()->create(['role' => 'FINANZAS']);

        $service->sign($order, $procura);
        $this->assertSame('EN_FIRMA', $order->fresh()->status);

        $service->sign($order, $presidencia);
        $service->sign($order, $finanzas);

        $this->assertSame('FIRMADA', $order->fresh()->status);
        $this->assertSame(3, $order->signatures()->count());
    }

    public function test_rejects_signing_out_of_turn(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $this->expectException(ValidationException::class);
        $service->sign($order, $presidencia);
    }

    public function test_rejects_signing_by_wrong_role(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $finanzas = User::factory()->create(['role' => 'FINANZAS']);

        $this->expectException(ValidationException::class);
        $service->sign($order, $finanzas);
    }

    public function test_try_sign_is_silent_when_not_the_turn(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $presidencia = User::factory()->create(['role' => 'PRESIDENCIA']);

        $service->trySign($order, $presidencia);

        $this->assertSame(0, $order->signatures()->count());
    }

    public function test_without_configured_steps_order_needs_no_signatures(): void
    {
        $contractor = Contractor::factory()->create(['code' => 'CON-NOSIG']);
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $proposal = ProjectProposal::factory()->for($project)->create([
            'contractor_code' => $contractor->code, 'total_cost' => 5000, 'negotiated_advance_percent' => 20,
        ]);
        $project->update(['selected_contractor_code' => $contractor->code, 'selected_proposal_id' => $proposal->id]);
        $order = app(PaymentOrderService::class)->generate($project->fresh(), PaymentOrder::TYPE_ADVANCE);

        $service = app(PaymentSignatureService::class);
        $service->assertCanProceed($order, User::factory()->create(['role' => 'FINANZAS'])); // no lanza

        $this->assertTrue($service->isFullySigned($order));
    }

    public function test_assert_can_proceed_blocks_when_an_earlier_required_step_is_pending(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $service->sign($order, User::factory()->create(['role' => 'PROCURA']));

        $this->expectException(ValidationException::class);
        $service->assertCanProceed($order, User::factory()->create(['role' => 'FINANZAS']));
    }

    public function test_assert_can_proceed_passes_for_the_actor_of_the_next_pending_step(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $service->sign($order, User::factory()->create(['role' => 'PROCURA']));
        $service->sign($order, User::factory()->create(['role' => 'PRESIDENCIA']));

        $service->assertCanProceed($order, User::factory()->create(['role' => 'FINANZAS'])); // no lanza: le toca a Finanzas
        $this->assertTrue(true);
    }

    public function test_assert_can_proceed_blocks_the_wrong_actor_even_with_correct_turn(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $service->sign($order, User::factory()->create(['role' => 'PROCURA']));
        $service->sign($order, User::factory()->create(['role' => 'PRESIDENCIA']));

        $this->expectException(ValidationException::class);
        $service->assertCanProceed($order, User::factory()->create(['role' => 'SUPERADMIN']));
    }

    public function test_optional_step_never_blocks_a_later_required_step(): void
    {
        $order = $this->orderWithSteps();
        PaymentSignatureStep::where('payment_type', 'ADVANCE')->where('step_order', 1)->update(['is_required' => false]);
        $service = app(PaymentSignatureService::class);

        // El paso 1 (PROCURA, ahora opcional) nunca se firma; el 2 (PRESIDENCIA) es obligatorio y debe poder avanzar igual.
        $service->assertCanProceed($order, User::factory()->create(['role' => 'PRESIDENCIA'])); // no lanza
        $this->assertTrue(true);
    }

    /**
     * SUPERADMIN "debe poder hacer todo" (decisión 2026-09-29): puede firmar
     * EXPLÍCITAMENTE cualquier paso, sin importar el rol/usuario configurado
     * — bypass total, distinto de cualquier otro rol.
     */
    public function test_superadmin_can_explicitly_sign_any_step_regardless_of_configured_role(): void
    {
        $step = PaymentSignatureStep::create(['payment_type' => 'FINAL', 'step_order' => 1, 'role' => 'FINANZAS', 'label' => 'Paso de Finanzas']);
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->assertTrue($step->canBeSignedBy($superadmin));
    }

    /**
     * Pero ese bypass NUNCA es silencioso: `trySign()` (el auto-firmado que
     * disparan aprobar/pagar/etc.) nunca firma por SUPERADMIN, así que
     * `assertCanProceed()` sigue bloqueándolo aunque técnicamente pudiera
     * firmar — debe pasar primero por el acto explícito de `sign()`.
     */
    public function test_superadmin_is_still_blocked_by_assert_can_proceed_until_it_signs_explicitly(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->expectException(ValidationException::class);
        $service->assertCanProceed($order, $superadmin);
    }

    public function test_try_sign_never_signs_silently_for_superadmin(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        $service->trySign($order, $superadmin);

        $this->assertSame(0, $order->signatures()->count());
    }

    public function test_superadmin_explicit_sign_advances_the_chain_for_the_next_real_actor(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);

        // Firma explícita del primer paso (PROCURA) — permitido por el bypass.
        $service->sign($order, $superadmin);
        $this->assertSame(1, $order->fresh()->signatures()->count());

        // Ahora le toca a PRESIDENCIA (paso 2) — ya no bloquea a ese actor real.
        $service->assertCanProceed($order, User::factory()->create(['role' => 'PRESIDENCIA']));
        $this->assertTrue(true);
    }

    public function test_cannot_sign_a_voided_or_paid_order(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $order->update(['status' => PaymentOrder::STATUS_ANULADA]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->sign($order, User::factory()->create(['role' => 'PROCURA']));
    }

    public function test_signature_line_reflects_status_in_order(): void
    {
        $order = $this->orderWithSteps();
        $service = app(PaymentSignatureService::class);
        $service->sign($order, User::factory()->create(['role' => 'PROCURA']));

        $line = $service->signatureLine($order);

        $this->assertSame('FIRMADO', $line[0]['status']);
        $this->assertSame('PROXIMO', $line[1]['status']);
        $this->assertSame('PENDIENTE', $line[2]['status']);
    }

    public function test_specific_user_step_only_signable_by_that_user(): void
    {
        $chosen = User::factory()->create(['role' => 'PROCURA']);
        $other = User::factory()->create(['role' => 'PROCURA']);
        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 1, 'user_id' => $chosen->id, 'label' => 'Director de Procura']);

        $contractor = Contractor::factory()->create(['code' => 'CON-USR']);
        $project = Project::factory()->create(['status' => 'CONTRATADO']);
        $proposal = ProjectProposal::factory()->for($project)->create([
            'contractor_code' => $contractor->code, 'total_cost' => 8000, 'negotiated_advance_percent' => 25,
        ]);
        $project->update(['selected_contractor_code' => $contractor->code, 'selected_proposal_id' => $proposal->id]);
        $order = app(PaymentOrderService::class)->generate($project->fresh(), PaymentOrder::TYPE_ADVANCE);

        $service = app(PaymentSignatureService::class);
        $this->expectException(ValidationException::class);
        $service->sign($order, $other);
    }
}
