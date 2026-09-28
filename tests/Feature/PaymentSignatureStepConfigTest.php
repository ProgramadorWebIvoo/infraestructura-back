<?php

namespace Tests\Feature;

use App\Models\PaymentSignatureStep;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentSignatureStepConfigTest extends TestCase
{
    use RefreshDatabase;

    private function headers(string $role = 'ADMIN'): array
    {
        $user = User::factory()->create(['role' => $role]);
        $this->app['auth']->forgetGuards();

        return ['Authorization' => 'Bearer ' . $user->createToken('test')->plainTextToken];
    }

    public function test_admin_creates_updates_and_deletes_a_step(): void
    {
        $headers = $this->headers();

        $create = $this->postJson('/api/payment-signature-steps/config', [
            'paymentType' => 'ADVANCE', 'stepOrder' => 1, 'role' => 'PROCURA', 'label' => 'Elaboración',
        ], $headers)->assertCreated();
        $id = $create->json('id');

        $this->getJson('/api/payment-signature-steps/config', $headers)->assertOk()->assertJsonCount(1, 'data');

        $this->patchJson("/api/payment-signature-steps/config/{$id}", ['label' => 'Elaboración de la orden'], $headers)
            ->assertOk()->assertJsonPath('label', 'Elaboración de la orden');

        $this->deleteJson("/api/payment-signature-steps/config/{$id}", [], $headers)->assertOk();
        $this->assertDatabaseMissing('payment_signature_steps', ['id' => $id]);
    }

    public function test_role_and_user_are_mutually_exclusive(): void
    {
        $headers = $this->headers();
        $target = User::factory()->create();

        $this->postJson('/api/payment-signature-steps/config', [
            'paymentType' => 'ADVANCE', 'stepOrder' => 1, 'role' => 'PROCURA', 'userId' => $target->id, 'label' => 'X',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_step_requires_a_role_or_a_user(): void
    {
        $headers = $this->headers();

        $this->postJson('/api/payment-signature-steps/config', [
            'paymentType' => 'ADVANCE', 'stepOrder' => 1, 'label' => 'X',
        ], $headers)->assertStatus(422)->assertJsonValidationErrors('role');
    }

    public function test_only_admin_roles_can_manage_steps(): void
    {
        $this->getJson('/api/payment-signature-steps/config', $this->headers('PROCURA'))->assertForbidden();
    }
}
