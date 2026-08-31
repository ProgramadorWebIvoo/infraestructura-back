<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_endpoint_is_open_to_any_authenticated_role(): void
    {
        $user = User::factory()->create(['role' => 'PRESIDENCIA']);

        $response = $this->actingAs($user)->getJson('/api/currencies/base');

        $response->assertStatus(200);
        $response->assertJsonPath('data.code', 'USD');
        $response->assertJsonPath('data.rateToUsd', 1);
    }

    public function test_base_endpoint_returns_rate_for_non_usd_base(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $eur = Currency::where('code', 'EUR')->firstOrFail();
        ExchangeRate::create(['currency_code' => 'EUR', 'rate_to_usd' => 1.08, 'source' => 'BCV', 'effective_at' => now()->subDay()]);
        $this->actingAs($admin)->postJson("/api/currencies/{$eur->id}/set-base")->assertStatus(200);

        $response = $this->actingAs($admin)->getJson('/api/currencies/base');

        $response->assertStatus(200);
        $response->assertJsonPath('data.code', 'EUR');
        $response->assertJsonPath('data.rateToUsd', 1.08);
    }

    public function test_base_endpoint_returns_null_rate_when_no_exchange_rate_exists(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $eur = Currency::where('code', 'EUR')->firstOrFail();
        $this->actingAs($admin)->postJson("/api/currencies/{$eur->id}/set-base")->assertStatus(200);

        $response = $this->actingAs($admin)->getJson('/api/currencies/base');

        $response->assertStatus(200);
        $response->assertJsonPath('data.rateToUsd', null);
    }

    public function test_creating_a_currency_notifies_recipients(): void
    {
        Notification::fake();
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        // Destinatario distinto del actor — el propio actor no debe recibir
        // notificación de su propia acción (ver
        // NotificationDispatcherTest::test_actor_does_not_receive_its_own_notification).
        $otroAdmin = User::factory()->create(['role' => 'SUPERADMIN']);

        // Regresión del Hallazgo 1 (auditoría Fase 0-1): CurrencyController
        // auditaba vía ConfigAuditLog::recordAdminAction() sin notificar a
        // nadie — recordAdminAction() ahora dispara notify() internamente.
        $this->actingAs($admin)->postJson('/api/currencies', [
            'code' => 'gbp',
            'name' => 'Libra esterlina',
            'symbol' => '£',
        ])->assertStatus(201);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $otroAdmin->id,
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
            'code' => 'gbp',
            'name' => 'Libra esterlina',
            'symbol' => '£',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('currencies', ['code' => 'GBP', 'name' => 'Libra esterlina', 'is_base' => false, 'is_active' => true]);
        $response->assertJsonPath('data.auditLog.action', 'Alta de moneda');
        $response->assertJsonPath('data.auditLog.entityType', 'currency');
    }

    public function test_rejects_code_shorter_than_three_letters(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($admin)
            ->postJson('/api/currencies', ['code' => 'EU', 'name' => 'Euro', 'symbol' => '€'])
            ->assertStatus(422);
    }

    public function test_rejects_code_longer_than_three_letters(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($admin)
            ->postJson('/api/currencies', ['code' => 'EURO', 'name' => 'Euro', 'symbol' => '€'])
            ->assertStatus(422);
    }

    public function test_rejects_name_above_eighty_characters(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($admin)
            ->postJson('/api/currencies', ['code' => 'EUR', 'name' => str_repeat('a', 81), 'symbol' => '€'])
            ->assertStatus(422);
    }

    public function test_accepts_name_at_the_exact_eighty_character_boundary(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($admin)
            ->postJson('/api/currencies', ['code' => 'GBP', 'name' => str_repeat('a', 80), 'symbol' => '£'])
            ->assertStatus(201);
    }

    public function test_rejects_symbol_above_eight_characters(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($admin)
            ->postJson('/api/currencies', ['code' => 'EUR', 'name' => 'Euro', 'symbol' => str_repeat('$', 9)])
            ->assertStatus(422);
    }

    public function test_accepts_symbol_at_the_exact_eight_character_boundary(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($admin)
            ->postJson('/api/currencies', ['code' => 'GBP', 'name' => 'Libra esterlina', 'symbol' => str_repeat('$', 8)])
            ->assertStatus(201);
    }

    public function test_cannot_add_a_duplicate_currency_code(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        Currency::create(['code' => 'GBP', 'name' => 'Libra esterlina', 'symbol' => '£', 'is_base' => false, 'is_active' => true]);

        $this->actingAs($admin)
            ->postJson('/api/currencies', ['code' => 'GBP', 'name' => 'Libra (dup)', 'symbol' => '£'])
            ->assertStatus(422);
    }

    public function test_superadmin_can_update_a_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $currency = Currency::create(['code' => 'GBP', 'name' => 'Libra esterlina', 'symbol' => '£', 'is_base' => false, 'is_active' => true]);

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
        $gbp = Currency::create(['code' => 'GBP', 'name' => 'Libra esterlina', 'symbol' => '£', 'is_base' => false, 'is_active' => true]);

        $response = $this->actingAs($admin)->postJson("/api/currencies/{$gbp->id}/set-base");

        $response->assertStatus(200);
        $this->assertDatabaseHas('currencies', ['code' => 'GBP', 'is_base' => true]);
        $this->assertDatabaseHas('currencies', ['code' => 'USD', 'is_base' => false]);
        $response->assertJsonPath('data.auditLog.action', 'Cambio de moneda base');
    }

    public function test_cannot_set_an_inactive_currency_as_base(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $gbp = Currency::create(['code' => 'GBP', 'name' => 'Libra esterlina', 'symbol' => '£', 'is_base' => false, 'is_active' => false]);

        $this->actingAs($admin)
            ->postJson("/api/currencies/{$gbp->id}/set-base")
            ->assertStatus(422);
    }

    public function test_superadmin_can_delete_a_non_base_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $gbp = Currency::create(['code' => 'GBP', 'name' => 'Libra esterlina', 'symbol' => '£', 'is_base' => false, 'is_active' => true]);

        // 200 (no 204) porque el response lleva el auditLog recién creado —
        // el frontend lo necesita para insertar la entrada en vivo en el
        // panel de auditoría (mismo patrón que store/update/setBase).
        $response = $this->actingAs($admin)->deleteJson("/api/currencies/{$gbp->id}");
        $response->assertStatus(200);
        $response->assertJsonPath('data.auditLog.action', 'Eliminación de moneda');
        $this->assertDatabaseMissing('currencies', ['id' => $gbp->id]);
    }

    public function test_cannot_delete_the_base_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $base = Currency::where('is_base', true)->firstOrFail();

        $this->actingAs($admin)->deleteJson("/api/currencies/{$base->id}")->assertStatus(422);
    }

    public function test_seeds_the_bcv_official_currencies(): void
    {
        // EUR/USD llegan sembradas por
        // 2026_08_31_120100_seed_bcv_official_currencies — regresión de que
        // la migración corre en cualquier entorno (incluido RefreshDatabase
        // en test), no solo en producción.
        foreach (['USD', 'EUR'] as $code) {
            $this->assertDatabaseHas('currencies', ['code' => $code, 'is_official' => true]);
        }
    }

    public function test_cannot_rename_an_official_bcv_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $eur = Currency::where('code', 'EUR')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/currencies/{$eur->id}", ['name' => 'Euro (renombrado)'])
            ->assertStatus(422);
    }

    public function test_cannot_change_symbol_of_an_official_bcv_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $eur = Currency::where('code', 'EUR')->firstOrFail();

        $this->actingAs($admin)
            ->patchJson("/api/currencies/{$eur->id}", ['symbol' => 'E'])
            ->assertStatus(422);
    }

    public function test_can_toggle_active_state_of_an_official_bcv_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $eur = Currency::where('code', 'EUR')->firstOrFail();

        $response = $this->actingAs($admin)->patchJson("/api/currencies/{$eur->id}", ['is_active' => false]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('currencies', ['id' => $eur->id, 'is_active' => false, 'is_official' => true]);
    }

    public function test_cannot_delete_an_official_bcv_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        $eur = Currency::where('code', 'EUR')->firstOrFail();

        $this->actingAs($admin)
            ->deleteJson("/api/currencies/{$eur->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('currencies', ['id' => $eur->id]);
    }

    public function test_database_rejects_a_second_base_currency_outside_the_application_layer(): void
    {
        // "Exactamente una moneda base" no debe depender únicamente de que
        // CurrencyController::setBase() sea el único camino de escritura —
        // el propio esquema (índice único parcial) debe rechazar un segundo
        // is_base=true aunque se escriba por fuera del controller.
        $gbp = Currency::create(['code' => 'GBP', 'name' => 'Libra esterlina', 'symbol' => '£', 'is_base' => false, 'is_active' => true]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        Currency::where('id', $gbp->id)->update(['is_base' => true]);
    }
}
