<?php

namespace Tests\Feature;

use App\Models\Contractor;
use App\Models\PaymentOrder;
use App\Models\PaymentSignatureStep;
use App\Models\Project;
use App\Models\ProjectProposal;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** F4 Bloque C: bandeja "Firmas pendientes", accesible a cualquier rol con un paso configurado a su nombre. */
class PaymentSignaturePendingInboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_role_without_any_configured_step_has_no_configured_steps(): void
    {
        $user = User::factory()->create(['role' => 'AUDITORIA']);

        $response = $this->actingAs($user)->getJson('/api/payment-orders/pending-signatures');

        $response->assertOk()->assertJsonPath('hasConfiguredSteps', false)->assertJsonPath('orders', []);
    }

    public function test_role_outside_the_traditional_circuit_can_see_and_sign_its_own_step(): void
    {
        $contractor = Contractor::factory()->create();
        $infra = User::factory()->create(['role' => 'INFRAESTRUCTURA']);
        $procura = User::factory()->create(['role' => 'PROCURA']);

        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 1, 'role' => 'PROCURA', 'label' => 'Elaboración']);
        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 2, 'role' => 'INFRAESTRUCTURA', 'label' => 'Visto bueno técnico']);

        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id, 'contractor_code' => $contractor->code,
            'total_cost' => 10000, 'negotiated_advance_percent' => 30,
        ]);
        $this->actingAs($procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $contractor->code, 'proposalId' => $proposal->id,
        ])->assertStatus(200);

        // INFRAESTRUCTURA sí tiene un paso configurado, y le toca firmar (paso 1 ya lo firmó Procura).
        $inbox = $this->actingAs($infra)->getJson('/api/payment-orders/pending-signatures');
        $inbox->assertOk()->assertJsonPath('hasConfiguredSteps', true);
        $this->assertCount(1, $inbox->json('orders'));

        $order = PaymentOrder::where('project_id', $project->id)->firstOrFail();

        // Puede ver el detalle sin ser ADMIN/SUPERADMIN/PROCURA/PRESIDENCIA/FINANZAS.
        $this->actingAs($infra)->getJson("/api/payment-orders/{$order->id}")->assertOk()->assertJsonPath('data.canSign', true);

        // Y puede firmarlo.
        $this->actingAs($infra)->postJson("/api/payment-orders/{$order->id}/sign")->assertStatus(201);

        // Ya no aparece pendiente tras firmar.
        $inboxAfter = $this->actingAs($infra)->getJson('/api/payment-orders/pending-signatures');
        $this->assertCount(0, $inboxAfter->json('orders'));
    }

    /**
     * SUPERADMIN "debe poder hacer todo" pero nunca en silencio (decisión
     * 2026-09-29): ve CUALQUIER orden con un paso pendiente (bypass total en
     * la bandeja), pero firmar solo ocurre si él mismo hace clic en Firmar
     * explícitamente — nunca queda ya firmada de antemano ni como efecto
     * colateral de otra acción.
     */
    public function test_superadmin_sees_any_pending_order_and_must_sign_explicitly(): void
    {
        $contractor = Contractor::factory()->create();
        $superadmin = User::factory()->create(['role' => 'SUPERADMIN']);
        $procura = User::factory()->create(['role' => 'PROCURA']);

        // Paso configurado para FINANZAS — SUPERADMIN no tiene ese rol.
        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 1, 'role' => 'FINANZAS', 'label' => 'Constancia de pago']);

        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id, 'contractor_code' => $contractor->code,
            'total_cost' => 10000, 'negotiated_advance_percent' => 30,
        ]);
        $this->actingAs($procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $contractor->code, 'proposalId' => $proposal->id,
        ])->assertStatus(200);

        $order = PaymentOrder::where('project_id', $project->id)->firstOrFail();
        $this->assertSame(0, $order->signatures()->count(), 'select-contractor no debe firmar nada silenciosamente para nadie');

        $inbox = $this->actingAs($superadmin)->getJson('/api/payment-orders/pending-signatures');
        $inbox->assertOk()->assertJsonPath('hasConfiguredSteps', true);
        $this->assertCount(1, $inbox->json('orders'), 'SUPERADMIN ve la orden aunque el paso sea de FINANZAS');

        // Firma explícita: permitida por el bypass.
        $this->actingAs($superadmin)->postJson("/api/payment-orders/{$order->id}/sign")->assertStatus(201);
        $this->assertSame(1, $order->fresh()->signatures()->count());
    }

    public function test_role_with_no_matching_step_in_this_order_cannot_view_it(): void
    {
        $contractor = Contractor::factory()->create();
        $procura = User::factory()->create(['role' => 'PROCURA']);
        $marketing = User::factory()->create(['role' => 'MARKETING']);

        PaymentSignatureStep::create(['payment_type' => 'ADVANCE', 'step_order' => 1, 'role' => 'PROCURA', 'label' => 'Elaboración']);

        $project = Project::factory()->create(['status' => 'COMPARATIVA_ENVIADA']);
        $proposal = ProjectProposal::factory()->create([
            'project_id' => $project->id, 'contractor_code' => $contractor->code,
            'total_cost' => 10000, 'negotiated_advance_percent' => 30,
        ]);
        $this->actingAs($procura)->postJson("/api/projects/{$project->id}/select-contractor", [
            'contractorCode' => $contractor->code, 'proposalId' => $proposal->id,
        ])->assertStatus(200);

        $order = PaymentOrder::where('project_id', $project->id)->firstOrFail();

        $this->actingAs($marketing)->getJson("/api/payment-orders/{$order->id}")->assertStatus(403);
    }
}
