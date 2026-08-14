<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_a_currency_notifies_recipients(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        // Regresión del Hallazgo 1 (auditoría Fase 0-1): CurrencyController
        // auditaba vía ConfigAuditLog::recordAdminAction() sin notificar a
        // nadie — recordAdminAction() ahora dispara notify() internamente.
        $this->actingAs($admin)->postJson('/api/currencies', [
            'code' => 'eur',
            'name' => 'Euro',
            'symbol' => '€',
        ])->assertStatus(201);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $admin->id,
            'action' => 'Alta de moneda',
        ]);
    }

    public function test_index_lists_currencies_base_first(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $response = $this->actingAs($admin)->getJson('/api/currencies');

        $response->assertStatus(200);
        $response->assertJsonPath('data.0.code', 'USD');
        $response->assertJsonPath('data.0.is_base', true);
    }

    public function test_non_superadmin_cannot_access_currencies(): void
    {
        $user = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($user)->getJson('/api/currencies')->assertStatus(403);
    }

    public function test_superadmin_can_add_a_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $response = $this->actingAs($admin)->postJson('/api/currencies', [
            'code' => 'eur',
            'name' => 'Euro',
            'symbol' => '€',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('currencies', ['code' => 'EUR', 'name' => 'Euro', 'is_base' => false, 'is_active' => true]);
        $response->assertJsonPath('data.auditLog.action', 'Alta de moneda');
        $response->assertJsonPath('data.auditLog.entityType', 'currency');
    }

    public function test_cannot_add_a_duplicate_currency_code(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_base' => false, 'is_active' => true]);

        $this->actingAs($admin)
            ->postJson('/api/currencies', ['code' => 'EUR', 'name' => 'Euro (dup)', 'symbol' => '€'])
            ->assertStatus(422);
    }

    public function test_superadmin_can_update_a_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $currency = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_base' => false, 'is_active' => true]);

        $response = $this->actingAs($admin)->patchJson("/api/currencies/{$currency->id}", ['is_active' => false]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('currencies', ['id' => $currency->id, 'is_active' => false]);
        $response->assertJsonPath('data.auditLog.action', 'Modificación de moneda');
    }

    public function test_cannot_deactivate_the_base_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $base = Currency::where('is_base', true)->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/currencies/{$base->id}", ['is_active' => false])
            ->assertStatus(422);
    }

    public function test_superadmin_can_change_the_base_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_base' => false, 'is_active' => true]);

        $response = $this->actingAs($admin)->postJson("/api/currencies/{$eur->id}/set-base");

        $response->assertStatus(200);
        $this->assertDatabaseHas('currencies', ['code' => 'EUR', 'is_base' => true]);
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'is_base' => false]);
        $response->assertJsonPath('data.auditLog.action', 'Cambio de moneda base');
    }

    public function test_cannot_set_an_inactive_currency_as_base(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_base' => false, 'is_active' => false]);

        $this->actingAs($admin)
            ->postJson("/api/currencies/{$eur->id}/set-base")
            ->assertStatus(422);
    }

    public function test_superadmin_can_delete_a_non_base_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_base' => false, 'is_active' => true]);

        $this->actingAs($admin)->deleteJson("/api/currencies/{$eur->id}")->assertStatus(204);
        $this->assertDatabaseMissing('currencies', ['id' => $eur->id]);
    }

    public function test_cannot_delete_the_base_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $base = Currency::where('is_base', true)->firstOrFail();

        $this->actingAs($admin)->deleteJson("/api/currencies/{$base->id}")->assertStatus(422);
    }

    public function test_database_rejects_a_second_base_currency_outside_the_application_layer(): void
    {
        // "Exactamente una moneda base" no debe depender únicamente de que
        // CurrencyController::setBase() sea el único camino de escritura —
        // el propio esquema (índice único parcial) debe rechazar un segundo
        // is_base=true aunque se escriba por fuera del controller.
        $eur = Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_base' => false, 'is_active' => true]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Currency::where('id', $eur->id)->update(['is_base' => true]);
    }
}
