<?php

namespace Tests\Unit;

use App\DTO\ConversionResult;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Services\ConversionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests que validan el flujo multi-moneda COMPLETO:
 * EUR/BRL → USD conversión → EST/VAR% cálculos
 *
 * Usa datos reales en SQLite (RefreshDatabase) en vez de
 * Mockery::mock('overload:...') sobre Cache/ExchangeRate — esos mocks
 * reemplazan la clase para el resto del proceso PHP (Mockery::close() no
 * lo revierte), lo que rompía otros tests del mismo run que usaran
 * ExchangeRate::create() o el facade Cache real (ver ConversionServiceTest
 * para el mismo fix).
 */
class MultiCurrencyFlowTest extends TestCase
{
    use RefreshDatabase;

    private ConversionService $conversionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->conversionService = new ConversionService();

        Currency::updateOrCreate(['code' => 'USD'], ['name' => 'US Dollar', 'symbol' => '$', 'is_official' => true, 'is_active' => true]);
        Currency::updateOrCreate(['code' => 'EUR'], ['name' => 'Euro', 'symbol' => '€', 'is_official' => true, 'is_active' => true]);
        Currency::create(['code' => 'BRL', 'name' => 'Real', 'symbol' => 'R$', 'is_official' => false, 'is_active' => true]);
    }

    /**
     * Escenario 1: Proveedor cota en EUR
     * - Cotiza: 100 EUR/kg
     * - Sistema convierte a USD: 92 USD
     * - EST histórico: 100 USD
     * - VAR%: ((92 - 100) / 100) × 100 = -8%
     * - Dirección: DECREASE (< -5%)
     */
    public function test_eur_quotation_flow(): void
    {
        // Paso 1: Proveedor cota 100 EUR
        $quotedPrice = 100;

        // Paso 2: Convertir a USD (0.92 tasa EUR/USD)
        $this->seedPairRate('EUR', 'USD', 0.92);
        $conversionResult = $this->conversionService->convert($quotedPrice, 'EUR', 'USD');

        $proposalPriceUsd = $conversionResult->amountConverted;
        $this->assertAlmostEqual(92, $proposalPriceUsd, 0.01, "PROP en USD debe ser 92");

        // Paso 3: Calcular VAR% (asumiendo EST = 100)
        $est = 100;
        $varPercent = (($proposalPriceUsd - $est) / $est) * 100;
        $varDirection = $this->determineVariationDirection($varPercent);

        $this->assertAlmostEqual(-8, $varPercent, 0.01, "VAR% debe ser -8%");
        $this->assertEquals('decrease', $varDirection, "Dirección debe ser DECREASE");
    }

    /**
     * Escenario 2: Proveedor cota en BRL (moneda lejana)
     * - Cotiza: 500 BRL/kg
     * - Sistema convierte a USD: 100 USD
     * - EST histórico: 95 USD
     * - VAR%: ((100 - 95) / 95) × 100 = +5.26%
     * - Dirección: INCREASE (> 5%)
     */
    public function test_brl_quotation_flow(): void
    {
        $quotedPrice = 500;

        // Convertir a USD (0.20 tasa BRL/USD)
        $this->seedPairRate('BRL', 'USD', 0.20);
        $conversionResult = $this->conversionService->convert($quotedPrice, 'BRL', 'USD');

        $proposalPriceUsd = $conversionResult->amountConverted;
        $this->assertAlmostEqual(100, $proposalPriceUsd, 0.01, "PROP en USD debe ser 100");

        // Calcular VAR%
        $est = 95;
        $varPercent = (($proposalPriceUsd - $est) / $est) * 100;
        $varDirection = $this->determineVariationDirection($varPercent);

        $this->assertAlmostEqual(5.26, $varPercent, 0.01, "VAR% debe ser +5.26%");
        $this->assertEquals('increase', $varDirection, "Dirección debe ser INCREASE");
    }

    /**
     * Escenario 3: Múltiples cotizaciones en distintas monedas, vía sumItems() real.
     */
    public function test_mixed_currency_calculation_logic(): void
    {
        $this->seedPairRate('EUR', 'USD', 0.92);
        $this->seedPairRate('BRL', 'USD', 0.20);

        $total = $this->conversionService->sumItems([
            ['quantity' => 10, 'unitPrice' => 100, 'quoteCurrency' => 'EUR'],
            ['quantity' => 20, 'unitPrice' => 5, 'quoteCurrency' => 'BRL'],
        ], 'USD');

        // (10×100 EUR × 0.92) + (20×5 BRL × 0.20) = 920 + 20 = 940 USD
        $this->assertAlmostEqual(940, $total, 0.01);
    }

    /**
     * Escenario 4: EST con múltiples cotizaciones históricas en monedas distintas
     * - Histórico 1: 100 USD
     * - Histórico 2: 110 EUR ≈ 101.2 USD
     * - Histórico 3: 500 BRL = 100 USD
     * - EST = (100 + 101.2 + 100) / 3 = 100.4 USD
     *
     * Nueva cotización: 102 USD
     * VAR% = ((102 - 100.4) / 100.4) × 100 = +1.59%
     * Dirección: STABLE (dentro de ±5%)
     */
    public function test_est_from_mixed_currency_history(): void
    {
        // Histórico normalizado a USD (como está en BD)
        $historicalPrices = [100, 101.2, 100]; // Ya convertidos a USD
        $est = array_sum($historicalPrices) / count($historicalPrices);

        // Nueva cotización: 102 USD
        $proposalPrice = 102;

        // Calcular VAR%
        $varPercent = (($proposalPrice - $est) / $est) * 100;
        $varDirection = $this->determineVariationDirection($varPercent);

        $this->assertAlmostEqual(100.4, $est, 0.01);
        $this->assertAlmostEqual(1.59, $varPercent, 0.01);
        $this->assertEquals('stable', $varDirection);
    }

    /**
     * Escenario 5: Tasa Outdated Detection
     * Tasa de cambio EUR→USD de hace 25 horas debe marcarse como outdated
     */
    public function test_outdated_rate_detection_in_flow(): void
    {
        $oldDate = now()->subHours(25);

        $this->seedPairRate('EUR', 'USD', 0.92, $oldDate->copy()->subHour());

        // Convertir con fecha antigua
        $result = $this->conversionService->convert(100, 'EUR', 'USD', $oldDate);

        $this->assertTrue($result->isOutdated, "Tasa con >24h debe estar outdated");
        $this->assertGreaterThan(24, $result->rateAgeHours());
    }

    /**
     * Escenario 6: Precisión en conversiones múltiples (no redondeo compuesto)
     * Validar que NO se hace: 100 EUR × 0.92 × 0.20 = 18.4 BRL (INCORRECTO)
     * Sino: 100 EUR @ 0.92 = 92 USD, luego 92 USD @ (1/0.20) = 460 BRL (CORRECTO)
     */
    public function test_chained_conversion_precision(): void
    {
        $this->seedPairRate('EUR', 'USD', 0.92);
        $this->seedPairRate('BRL', 'USD', 0.20);

        $usdResult = $this->conversionService->convert(100, 'EUR', 'USD');
        $brlResult = $this->conversionService->convert($usdResult->amountConverted, 'USD', 'BRL');

        // Verificar NO es: 100 × 0.92 × 0.20 = 18.4 (INCORRECTO)
        // Sino es: 100 × 0.92 × 5 = 460 (CORRECTO)
        $this->assertAlmostEqual(92, $usdResult->amountConverted, 0.01, "EUR 100 @ 0.92 = USD 92");
        $this->assertAlmostEqual(460, $brlResult->amountConverted, 0.01, "USD 92 @ 5.0 = BRL 460");
        $this->assertNotAlmostEqual(18.4, $brlResult->amountConverted, 0.01, "No debe ser 18.4 BRL");
    }

    /**
     * Escenario 7: Boundary cases de VAR%
     * Exactamente +5% y -5% deben ser "stable", no "increase/decrease"
     */
    public function test_variation_boundary_at_exactly_5_percent(): void
    {
        $est = 100;

        // Exactamente +5%
        $proposalPrice = 105;
        $varPercent = (($proposalPrice - $est) / $est) * 100;
        $direction = $this->determineVariationDirection($varPercent);
        $this->assertEquals('stable', $direction, "+5% debe ser stable");

        // Exactamente -5%
        $proposalPrice = 95;
        $varPercent = (($proposalPrice - $est) / $est) * 100;
        $direction = $this->determineVariationDirection($varPercent);
        $this->assertEquals('stable', $direction, "-5% debe ser stable");

        // Justo arriba de +5%
        $proposalPrice = 105.01;
        $varPercent = (($proposalPrice - $est) / $est) * 100;
        $direction = $this->determineVariationDirection($varPercent);
        $this->assertEquals('increase', $direction, "+5.01% debe ser increase");
    }

    // ========== HELPERS ==========

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

    private function determineVariationDirection(float $varPercent): string
    {
        if ($varPercent > 5) {
            return 'increase';
        } elseif ($varPercent < -5) {
            return 'decrease';
        }
        return 'stable';
    }

    private function assertAlmostEqual(
        float $expected,
        float $actual,
        float $tolerance = 0.01,
        string $message = ''
    ): void {
        $this->assertTrue(
            abs($expected - $actual) <= $tolerance,
            $message ?: "Esperado {$expected}, obtenido {$actual} (diferencia > {$tolerance})"
        );
    }

    private function assertNotAlmostEqual(
        float $unexpectedValue,
        float $actual,
        float $tolerance = 0.01,
        string $message = ''
    ): void {
        $this->assertFalse(
            abs($unexpectedValue - $actual) <= $tolerance,
            $message ?: "NO debería ser {$unexpectedValue}, obtenido {$actual}"
        );
    }
}
