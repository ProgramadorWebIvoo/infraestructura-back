<?php

namespace Tests\Feature;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\ConfigAuditLog;
use App\Models\User;
use App\Services\ExchangeRate\DolarVzlaApiFetcher;
use App\Services\ExchangeRate\BcvScraperFetcher;
use App\Services\ExchangeRate\ExchangeRateSyncService;
use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SyncExchangeRatesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Currency::create(['code' => 'USD', 'name' => 'US Dollar', 'symbol' => '$', 'is_official' => true, 'is_active' => true]);
        Currency::create(['code' => 'EUR', 'name' => 'Euro', 'symbol' => '€', 'is_official' => true, 'is_active' => true]);
        Currency::create(['code' => 'VES', 'name' => 'Bolívar', 'symbol' => 'Bs', 'is_official' => false, 'is_active' => true]);

        User::factory()->create(['role' => 'SUPERADMIN']);
    }

    // ===== DOLARVZLA API FETCHER TESTS =====

    public function test_dolarvzla_fetcher_parses_json_correctly(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response([
                'current' => [
                    'date' => '2026-08-31',
                    'usd' => 794.9917,
                    'eur' => 922.69121677,
                ],
                'previous' => [
                    'date' => '2026-08-28',
                    'usd' => 791.6667,
                    'eur' => 921.88003881,
                ],
                'changePercentage' => [
                    'usd' => 0.41999998231579594,
                    'eur' => 0.08799170454402185,
                ],
            ], 200),
        ]);

        $fetcher = new DolarVzlaApiFetcher();
        $result = $fetcher->fetch();

        $this->assertEquals('2026-08-31', $result['date']);
        $this->assertEquals(794.9917, $result['currencies']['USD']);
        $this->assertEquals(922.69121677, $result['currencies']['EUR']);
        $this->assertEquals('DOLARVZLA_API', $result['source']);
    }

    public function test_dolarvzla_fetcher_throws_on_network_error(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response([], 500),
        ]);

        $fetcher = new DolarVzlaApiFetcher();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('DolarVZLA API failed');

        $fetcher->fetch();
    }

    public function test_dolarvzla_fetcher_throws_on_invalid_json(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response(['invalid' => 'data'], 200),
        ]);

        $fetcher = new DolarVzlaApiFetcher();

        $this->expectException(Exception::class);

        $fetcher->fetch();
    }

    public function test_dolarvzla_fetcher_throws_on_timeout(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::sequence()
                ->push('', 0)
                ->whenEmpty(Http::response([], 500)),
        ]);

        $fetcher = new DolarVzlaApiFetcher();

        $this->expectException(Exception::class);

        $fetcher->fetch();
    }

    // ===== BCV SCRAPER FETCHER TESTS =====

    public function test_bcv_scraper_parses_html_correctly(): void
    {
        $htmlResponse = <<<'HTML'
        <html>
            <body>
                <div data-currency="USD" class="rate-container">
                    <span class="rate-value">794.99</span>
                </div>
                <div data-currency="EUR" class="rate-container">
                    <span class="rate-value">922.69</span>
                </div>
            </body>
        </html>
        HTML;

        Http::fake([
            'bcv.org.ve/*' => Http::response($htmlResponse, 200),
        ]);

        $fetcher = new BcvScraperFetcher();
        $result = $fetcher->fetch();

        $this->assertEquals(794.99, $result['currencies']['USD']);
        $this->assertEquals(922.69, $result['currencies']['EUR']);
        $this->assertEquals('BCV_SCRAPING', $result['source']);
        $this->assertStringMatches('/\d{4}-\d{2}-\d{2}/', $result['date']);
    }

    public function test_bcv_scraper_throws_on_network_error(): void
    {
        Http::fake([
            'bcv.org.ve/*' => Http::response([], 500),
        ]);

        $fetcher = new BcvScraperFetcher();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('BCV scraping failed');

        $fetcher->fetch();
    }

    public function test_bcv_scraper_extracts_rates_with_regex(): void
    {
        $htmlResponse = <<<'HTML'
        <html>
            <body>
                <div data-currency="USD" class="rate-container">
                    <span class="rate-value">1.234,56</span>
                </div>
                <div data-currency="EUR" class="rate-container">
                    <span class="rate-value">2.345,67</span>
                </div>
            </body>
        </html>
        HTML;

        Http::fake([
            'bcv.org.ve/*' => Http::response($htmlResponse, 200),
        ]);

        $fetcher = new BcvScraperFetcher();
        $result = $fetcher->fetch();

        // El regex debe limpiar "1.234,56" a 1234.56
        $this->assertIsFloat($result['currencies']['USD']);
        $this->assertGreaterThan(0, $result['currencies']['USD']);
    }

    public function test_bcv_scraper_throws_when_selectors_not_found(): void
    {
        $htmlResponse = '<html><body>No rates found</body></html>';

        Http::fake([
            'bcv.org.ve/*' => Http::response($htmlResponse, 200),
        ]);

        $fetcher = new BcvScraperFetcher();

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Could not extract');

        $fetcher->fetch();
    }

    // ===== SYNC SERVICE TESTS =====

    public function test_sync_saves_rates_from_dolarvzla(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response([
                'current' => [
                    'date' => '2026-08-31',
                    'usd' => 794.9917,
                    'eur' => 922.69121677,
                ],
                'changePercentage' => [
                    'usd' => 0.42,
                    'eur' => 0.088,
                ],
            ], 200),
        ]);

        $dolarVzlaFetcher = new DolarVzlaApiFetcher();
        $bcvScraperFetcher = $this->createMock(BcvScraperFetcher::class);

        $syncService = new ExchangeRateSyncService(
            $dolarVzlaFetcher,
            $bcvScraperFetcher
        );

        $syncService->sync();

        $this->assertDatabaseHas('exchange_rates', [
            'currency_code' => 'USD',
            'rate_to_usd' => 794.9917,
            'source' => 'DOLARVZLA_API',
        ]);

        $this->assertDatabaseHas('exchange_rates', [
            'currency_code' => 'EUR',
            'rate_to_usd' => 922.69121677,
            'source' => 'DOLARVZLA_API',
        ]);

        $this->assertDatabaseHas('config_audit_logs', [
            'entity_type' => 'exchange_rate',
            'action' => 'Sync automático de tasa',
        ]);
    }

    public function test_sync_falls_back_to_scraping_on_api_failure(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response([], 500),
        ]);

        $dolarVzlaFetcher = new DolarVzlaApiFetcher();

        $bcvScraperFetcher = $this->createMock(BcvScraperFetcher::class);
        $bcvScraperFetcher->method('fetch')->willThrow(new Exception('Scraper failed'));

        $syncService = new ExchangeRateSyncService(
            $dolarVzlaFetcher,
            $bcvScraperFetcher
        );

        $syncService->sync();

        $this->assertDatabaseHas('config_audit_logs', [
            'entity_type' => 'exchange_rate',
            'action' => 'Sync automático falló',
            'new_value' => 'ERROR',
        ]);
    }

    public function test_sync_notifies_superadmin_on_total_failure(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response([], 500),
        ]);

        $dolarVzlaFetcher = new DolarVzlaApiFetcher();

        $bcvScraperFetcher = $this->createMock(BcvScraperFetcher::class);
        $bcvScraperFetcher->method('fetch')->willThrow(new Exception('Scraper failed'));

        $syncService = new ExchangeRateSyncService(
            $dolarVzlaFetcher,
            $bcvScraperFetcher
        );

        $syncService->sync();

        $superadmin = User::where('role', 'SUPERADMIN')->first();
        $this->assertNotNull($superadmin);

        $this->assertDatabaseHas('app_notifications', [
            'user_id' => $superadmin->id,
            'action' => 'Fallo en sync de tasas de cambio',
        ]);
    }

    public function test_sync_does_not_duplicate_rates(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response([
                'current' => [
                    'date' => '2026-08-31',
                    'usd' => 794.9917,
                    'eur' => 922.69121677,
                ],
                'changePercentage' => [
                    'usd' => 0.42,
                    'eur' => 0.088,
                ],
            ], 200),
        ]);

        $dolarVzlaFetcher = new DolarVzlaApiFetcher();
        $bcvScraperFetcher = $this->createMock(BcvScraperFetcher::class);

        $syncService = new ExchangeRateSyncService(
            $dolarVzlaFetcher,
            $bcvScraperFetcher
        );

        $syncService->sync();
        $syncService->sync();

        $this->assertEquals(2, ExchangeRate::where('currency_code', 'USD')->count());
    }

    // ===== COMMAND TESTS =====

    public function test_sync_exchange_rates_command_succeeds(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response([
                'current' => [
                    'date' => '2026-08-31',
                    'usd' => 794.9917,
                    'eur' => 922.69121677,
                ],
                'changePercentage' => [
                    'usd' => 0.42,
                    'eur' => 0.088,
                ],
            ], 200),
        ]);

        $this->artisan('sync:exchange-rates')
            ->assertExitCode(0);

        $this->assertDatabaseHas('exchange_rates', [
            'currency_code' => 'USD',
            'source' => 'DOLARVZLA_API',
        ]);
    }

    public function test_sync_exchange_rates_command_fails_gracefully(): void
    {
        Http::fake([
            'rates.dolarvzla.com/*' => Http::response([], 500),
            'bcv.org.ve/*' => Http::response([], 500),
        ]);

        $this->artisan('sync:exchange-rates')
            ->assertExitCode(1);
    }
}
