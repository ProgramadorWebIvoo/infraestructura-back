<?php

namespace Tests\Unit;

use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\ConversionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Usa datos reales en SQLite (RefreshDatabase) en vez de mockear
 * ExchangeRate::rateBetween() con Mockery::mock('overload:...').
 *
 * Los mocks "overload" reemplazan la clase globalmente para el resto del
 * proceso PHP (Mockery::close() no lo revierte) — como rateBetween() es un
 * método estático, esto rompía cualquier test posterior en la misma
 * ejecución que usara ExchangeRate::create() (ej. SyncExchangeRatesTest),
 * con "Call to a member function __call() on null". Sembrar filas reales
 * evita el problema y prueba la lógica real de bcvRateFor()/rateBetween().
 */
class ConversionServiceTest extends TestCase
{
    use RefreshDatabase;

    private ConversionService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ConversionService();

        // USD/EUR ya vienen sembradas por la migración
        // 2026_08_31_120100_seed_bcv_official_currencies (corre en cada
        // RefreshDatabase) — updateOrCreate en vez de create para no chocar
        // con el UNIQUE constraint de currencies.code.
        Currency::updateOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'is_official' => true, 'is_active' => true]);
        Currency::updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'is_official' => true, 'is_active' => true]);
        Currency::create(['code' => 'BRL', 'name' => 'Real', 'symbol' => 'R$', 'is_official' => false, 'is_active' => true]);
    }

    /**
     * Test 1: Conversión a misma moneda retorna monto original + tasa 1.0
     */
    public function test_convert_same_currency_returns_identity(): void
    {
        $result = $this->service->convert(100, 'USD', 'USD');

        $this->assertEquals(100, $result->amountConverted);
        $this->assertEquals(1.0, $result->rate);
        $this->assertFalse($result->isOutdated);
    }

    /**
     * Test 2: Tasa de cambio vigente se calcula correctamente usando rateBetween
     */
    public function test_convert_with_valid_rates(): void
    {
        $this->seedPairRate('EUR', 'USD', 0.92);

        $result = $this->service->convert(100, 'EUR', 'USD');

        $this->assertEquals(92, $result->amountConverted);
        $this->assertAlmostEqual(0.92, $result->rate, 0.0001);
    }

    /**
     * Test 3: Conversión cruzada EUR → BRL (a través de bolívares)
     */
    public function test_convert_cross_rate_eur_to_brl(): void
    {
        $this->seedPairRate('EUR', 'BRL', 4.6);

        $result = $this->service->convert(100, 'EUR', 'BRL');

        $this->assertEquals(460, $result->amountConverted);
        $this->assertAlmostEqual(4.6, $result->rate, 0.0001);
    }

    /**
     * Test 4: Detección de tasa outdated (>24 horas)
     */
    public function test_convert_detects_outdated_rate(): void
    {
        $oldDate = now()->subHours(25);

        $this->seedPairRate('EUR', 'USD', 0.92, $oldDate->copy()->subHour());

        $result = $this->service->convert(100, 'EUR', 'USD', $oldDate);

        $this->assertTrue($result->isOutdated);
        $this->assertGreaterThan(24, $result->rateAgeHours());
    }

    /**
     * Test 5: Excepción cuando tasas no están disponibles
     */
    public function test_convert_throws_when_rates_unavailable(): void
    {
        // EUR tiene tasa sembrada; INVALID_CURRENCY no existe en exchange_rates.
        $this->seedRate('EUR', 92);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Tasas de cambio no disponibles');

        $this->service->convert(100, 'EUR', 'INVALID_CURRENCY');
    }

    /**
     * Test 6: Suma de múltiples ítems en distintas monedas
     * Línea 1: 10 EUR × 10 = 100 EUR
     * Línea 2: 5 USD × 20 = 100 USD
     * Total en USD: 100 EUR (convertido) + 100 USD
     */
    public function test_sum_items_multiple_currencies(): void
    {
        // EUR → USD = 0.92
        $this->seedPairRate('EUR', 'USD', 0.92);

        $items = [
            [
                'quantity' => 10,
                'unitPrice' => 10,
                'quoteCurrency' => 'EUR',
            ],
            [
                'quantity' => 20,
                'unitPrice' => 5,
                'quoteCurrency' => 'USD',
            ],
        ];

        $total = $this->service->sumItems($items, 'USD');

        // (10×10 EUR × 0.92) + (20×5 USD) = 92 + 100 = 192
        $this->assertAlmostEqual(192, $total, 0.01);
    }

    /**
     * Test 7: getRate() retorna tasa actual entre monedas
     */
    public function test_get_rate_returns_current_rate(): void
    {
        $this->seedPairRate('EUR', 'USD', 0.92);

        $rate = $this->service->getRate('EUR', 'USD');

        $this->assertAlmostEqual(0.92, $rate, 0.0001);
    }

    /**
     * Test 8: getRate() retorna 0 en caso de error
     */
    public function test_get_rate_returns_zero_on_error(): void
    {
        // Ni EUR ni INVALID_CURRENCY tienen tasa sembrada.
        $rate = $this->service->getRate('EUR', 'INVALID_CURRENCY');

        $this->assertEquals(0, $rate);
    }

    /**
     * Test 9: Redondeo a 2 decimales
     */
    public function test_convert_rounds_to_two_decimals(): void
    {
        // 100.456 EUR × 1.0869565 ≈ 109.196...
        $this->seedPairRate('EUR', 'USD', 1.0869565);

        $result = $this->service->convert(100.456, 'EUR', 'USD');

        // Verificar que al redondear a 2 decimales y formatearlo, queda igual
        $formatted = sprintf('%.2f', $result->amountConverted);
        $this->assertEquals($formatted, (string)$result->amountConverted,
            "Monto {$result->amountConverted} no está correctamente redondeado a 2 decimales"
        );
    }

    /**
     * Test 10: Precisión alta en tasas (6 decimales)
     */
    public function test_convert_rate_precision_six_decimals(): void
    {
        $this->seedPairRate('EUR', 'USD', 0.918765);

        $result = $this->service->convert(1000, 'EUR', 'USD');

        // Tasa debe tener máximo 6 decimales
        $rateStr = (string)$result->rate;
        $afterDecimal = strlen(explode('.', $rateStr)[1] ?? '');
        $this->assertLessThanOrEqual(6, $afterDecimal);
    }

    // ========== HELPERS PARA SEMBRAR TASAS REALES ==========

    /**
     * Siembra una tasa BCV cruda (Bs./unidad) para una moneda a una fecha.
     */
    private function seedRate(string $currencyCode, float $bcvRate, ?Carbon $effectiveAt = null): void
    {
        ExchangeRate::create([
            'currency_code' => $currencyCode,
            'rate_to_usd' => $bcvRate,
            'source' => 'TEST_SEED',
            'effective_at' => $effectiveAt ?? now(),
        ]);
    }

    /**
     * Siembra tasas BCV para que rateBetween($from, $to) = $ratio, usando
     * 100 Bs. como referencia arbitraria para $to.
     */
    private function seedPairRate(string $from, string $to, float $ratio, ?Carbon $effectiveAt = null): void
    {
        $this->seedRate($to, 100, $effectiveAt);
        $this->seedRate($from, 100 * $ratio, $effectiveAt);
    }

    private function assertAlmostEqual(float $expected, float $actual, float $tolerance = 0.01): void
    {
        $this->assertTrue(
            abs($expected - $actual) <= $tolerance,
            "Esperado {$expected}, obtenido {$actual} (diferencia > {$tolerance})"
        );
    }
}
