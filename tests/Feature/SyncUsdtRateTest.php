<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\User;
use App\Services\ExchangeRate\ExchangeRateSyncLogService;
use App\Services\ExchangeRate\UsdtApiFetcher;
use App\Services\ExchangeRate\UsdtRateSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
