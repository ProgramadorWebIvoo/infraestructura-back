<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Events\ExchangeRatesUpdated;
use App\Models\ExchangeRate;
use App\Models\ExchangeRateSyncLog;
use App\Models\User;
use App\Services\ExchangeRate\ExchangeRateSyncLogService;
use App\Services\ExchangeRate\UsdtApiFetcher;
use App\Services\ExchangeRate\UsdtRateSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncUsdtRateTest extends TestCase
{
    use RefreshDatabase;

    private const URL = 'usdt.com.ve/*';

    protected function setUp(): void
    {
        parent::setUp();

        // USDT ya viene sembrada por la migración seed_usdt_currency.
        User::factory()->create(['role' => 'SUPERADMIN']);
    }

    private function payload(float $buyRate = 959.4, string $source = 'binance'): array
    {
        return [
            'success' => true,
            'data' => [
                'binance' => ['buy_rate' => 959.4, 'sell_rate' => 958.055],
                'bcv' => ['rate' => 859.0629],
                'best' => ['source' => $source, 'buy_rate' => $buyRate],
                'captured_at' => '2026-09-30T14:05:19.637Z',
            ],
        ];
    }

    private function service(): UsdtRateSyncService
    {
        return new UsdtRateSyncService(new UsdtApiFetcher(), new ExchangeRateSyncLogService());
    }

    public function test_fetcher_uses_best_buy_rate_and_ignores_bcv(): void
    {
        Http::fake([self::URL => Http::response($this->payload(), 200)]);

        $result = (new UsdtApiFetcher())->fetch();

        $this->assertEquals(959.4, $result['rate']);
        $this->assertEquals('USDT_COM_VE:binance', $result['source']);
    }

    public function test_fetcher_throws_on_invalid_payload(): void
    {
        Http::fake([self::URL => Http::response(['success' => false], 200)]);

        $this->expectException(\Exception::class);
        (new UsdtApiFetcher())->fetch();
    }

    public function test_sync_saves_rate_to_history(): void
    {
        Http::fake([self::URL => Http::response($this->payload(), 200)]);

        $this->assertTrue($this->service()->sync());

        $this->assertDatabaseHas('exchange_rates', [
            'currency_code' => 'USDT',
            'source' => 'USDT_COM_VE:binance',
        ]);
    }

    public function test_sync_does_not_duplicate_unchanged_rate(): void
    {
        Http::fake([self::URL => Http::response($this->payload(), 200)]);

        $this->service()->sync();
        $this->service()->sync();

        $this->assertEquals(1, ExchangeRate::where('currency_code', 'USDT')->count());
    }

    public function test_sync_saves_new_row_when_rate_changes(): void
    {
        Http::fake([self::URL => Http::sequence()
            ->push($this->payload(959.4), 200)
            ->push($this->payload(961.0), 200)]);

        $this->service()->sync();
        $this->service()->sync();

        $this->assertEquals(2, ExchangeRate::where('currency_code', 'USDT')->count());
    }

    public function test_failure_is_isolated_and_logged_without_throwing(): void
    {
        Http::fake([self::URL => Http::response([], 500)]);

        $this->assertFalse($this->service()->sync());

        $this->assertDatabaseHas('exchange_rate_sync_logs', [
            'status' => 'FAILURE',
            'source' => 'USDT_COM_VE',
        ]);
        $this->assertEquals(0, ExchangeRate::where('currency_code', 'USDT')->count());
    }

    public function test_inactive_currency_skips_the_request(): void
    {
        Http::fake();
        Currency::where('code', 'USDT')->update(['is_active' => false]);

        $this->assertTrue($this->service()->sync());

        Http::assertNothingSent();
    }

    public function test_a_broadcast_failure_does_not_turn_the_sync_into_a_failure(): void
    {
        Http::fake([self::URL => Http::response($this->payload(), 200)]);
        Event::listen(ExchangeRatesUpdated::class, fn () => throw new \RuntimeException('Pusher caído'));

        $this->assertTrue($this->service()->sync());

        $this->assertEquals(1, ExchangeRate::where('currency_code', 'USDT')->count());
    }

    public function test_an_unchanged_rate_leaves_no_trace_in_the_sync_logs(): void
    {
        Http::fake([self::URL => Http::response($this->payload(), 200)]);

        $this->service()->sync();
        $this->service()->sync();
        $this->service()->sync();

        $this->assertEquals(1, ExchangeRateSyncLog::count());
    }

    public function test_last_sync_ignores_usdt_so_a_stale_bcv_is_not_masked(): void
    {
        ExchangeRateSyncLog::create(['status' => 'SUCCESS', 'source' => 'DOLARVZLA_API', 'rates_synced' => 2, 'executed_at' => now()->subDays(3)]);
        ExchangeRateSyncLog::create(['status' => 'SUCCESS', 'source' => 'USDT_COM_VE:binance', 'rates_synced' => 1, 'executed_at' => now()]);
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($admin)->getJson('/api/exchange-rates/last-sync')
            ->assertStatus(200)
            ->assertJsonPath('data.source', 'DOLARVZLA_API');
    }

    public function test_manual_sync_still_runs_bcv_when_usdt_fails(): void
    {
        Http::fake([
            self::URL => Http::response([], 500),
            'rates.dolarvzla.com/*' => Http::response([
                'current' => ['date' => '2026-09-30', 'usd' => 800.0, 'eur' => 900.0],
                'previous' => ['date' => '2026-09-29', 'usd' => 799.0, 'eur' => 899.0],
                'changePercentage' => ['usd' => 0.1, 'eur' => 0.1],
            ], 200),
        ]);
        $admin = User::factory()->create(['role' => 'SUPERADMIN']);

        $this->actingAs($admin)->postJson('/api/exchange-rates/sync')
            ->assertStatus(200)
            ->assertJsonPath('usdt_success', false);

        $this->assertEquals(1, ExchangeRate::where('currency_code', 'USD')->count());
    }
}
