<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExchangeRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_non_superadmin_cannot_access_exchange_rates(): void
    {
        $user = User::factory()->create(['role' => 'ADMIN']);

        $this->actingAs($user)->getJson('/api/exchange-rates')->assertStatus(403);
    }

    public function test_superadmin_can_load_a_rate(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        Currency::create(['code' => 'VES', 'name' => 'Bolívar', 'symbol' => 'Bs', 'is_base' => false, 'is_active' => true]);

        $response = $this->actingAs($admin)->postJson('/api/exchange-rates', [
            'currency_code' => 'ves',
            'rate_to_usd' => 0.0083,
            'source' => 'BCV',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('exchange_rates', ['currency_code' => 'VES', 'source' => 'BCV']);
        $response->assertJsonPath('data.auditLog.action', 'Carga de tasa de cambio');
    }

    public function test_rejects_rate_for_currency_not_in_catalog(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($admin)->postJson('/api/exchange-rates', [
            'currency_code' => 'ZZZ',
            'rate_to_usd' => 1.5,
            'source' => 'manual',
        ])->assertStatus(422);
    }

    public function test_rejects_manual_usd_rate(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($admin)->postJson('/api/exchange-rates', [
            'currency_code' => 'USD',
            'rate_to_usd' => 1,
            'source' => 'manual',
        ])->assertStatus(422);
    }

    public function test_rejects_non_positive_rate(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        Currency::create(['code' => 'VES', 'name' => 'Bolívar', 'symbol' => 'Bs', 'is_base' => false, 'is_active' => true]);

        $this->actingAs($admin)->postJson('/api/exchange-rates', [
            'currency_code' => 'VES',
            'rate_to_usd' => 0,
            'source' => 'manual',
        ])->assertStatus(422);
    }

    public function test_index_returns_only_latest_rate_per_currency(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        Currency::create(['code' => 'VES', 'name' => 'Bolívar', 'symbol' => 'Bs', 'is_base' => false, 'is_active' => true]);

        ExchangeRate::create(['currency_code' => 'VES', 'rate_to_usd' => 0.0080, 'source' => 'BCV', 'effective_at' => now()->subDays(2)]);
        ExchangeRate::create(['currency_code' => 'VES', 'rate_to_usd' => 0.0083, 'source' => 'BCV', 'effective_at' => now()]);

        $response = $this->actingAs($admin)->getJson('/api/exchange-rates');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.rate_to_usd', 0.0083);
    }

    public function test_history_returns_all_rates_for_a_currency_newest_first(): void
    {
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);
        Currency::create(['code' => 'VES', 'name' => 'Bolívar', 'symbol' => 'Bs', 'is_base' => false, 'is_active' => true]);

        ExchangeRate::create(['currency_code' => 'VES', 'rate_to_usd' => 0.0080, 'source' => 'BCV', 'effective_at' => now()->subDays(2)]);
        ExchangeRate::create(['currency_code' => 'VES', 'rate_to_usd' => 0.0083, 'source' => 'BCV', 'effective_at' => now()]);

        $response = $this->actingAs($admin)->getJson('/api/exchange-rates/VES/history');

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.rate_to_usd', 0.0083);
    }
}
