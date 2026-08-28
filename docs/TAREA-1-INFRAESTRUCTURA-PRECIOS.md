# 🚀 TAREA #1: Infraestructura de Precios — Servicios Base + Migración DB

**Estado:** 🟢 LISTA PARA IMPLEMENTAR  
**Estimación:** 5–6 días  
**Tipo:** Backend (no bloqueante para frontend)  
**Criterio de aceptación:** PR + tests + CONFIG APP

---

## Resumen Ejecutivo

Implementar servicios centralizados de cálculo de EST (precio estimado) y conversión de monedas, con desnormalización en BD para optimización. **Esta tarea es el cuello de botella de Fase 3.1.**

---

## Subtareas (Orden de Implementación)

### 1️⃣ PriceEstimationService (2 días)

**Archivo:** `app/Services/PriceEstimationService.php`

```php
class PriceEstimationService {
  /**
   * Calcula EST (precio estimado) de un producto por proveedor.
   * - Promedio histórico últimos 6 meses de product_price_history
   * - Fallback: último precio cotizado (catalog_product_suppliers)
   * - Return: DTO con value, source, dataPoints, periodMonths
   */
  public function getEstimatedPrice(
    int $catalogProductId,
    string $supplierCode,
    int $monthsBack = 6
  ): PriceEstimate
  
  /**
   * Batch: calcula EST para múltiples líneas.
   * Usado al procesar propuesta completa (evita N queries).
   */
  public function estimateLinesForProposal(
    SupplierMaterialProposal $proposal
  ): Collection<PriceEstimate>
}
```

**DTO Output:**
```php
class PriceEstimate {
  public decimal $value;              // Precio en USD
  public string $source;              // 'historical_avg' | 'last_quoted'
  public ?int $dataPoints;            // Cuántos precios en promedio
  public ?int $periodMonths;          // Período (6)
  public ?Carbon $referenceDate;      // Fecha de referencia
}
```

**Lógica:**
```
1. SELECT AVG(price_usd) FROM product_price_history 
   WHERE catalog_product_id = X 
   AND supplier_code = Y 
   AND quoted_at >= NOW() - 6 MONTHS

2. Si resultado NULL:
   → SELECT last_quoted_price_usd FROM catalog_product_suppliers
     WHERE catalog_product_id = X AND supplier_code = Y

3. Si ambos NULL:
   → return null (sin EST disponible)
```

**Tests Mínimos:**
```php
test('calcula promedio histórico 6 meses');
test('fallback a último precio si sin histórico');
test('devuelve null si sin histórico ni último precio');
test('batch estimates sin n+1 queries');
```

---

### 2️⃣ CurrencyService (1.5 días)

**Archivo:** `app/Services/CurrencyService.php`

```php
class CurrencyService {
  /**
   * Convierte monto a USD usando tasa vigente.
   * Valida que exchange_rates.updated_at < 24h.
   * Lanza exception si tasa desactualizada.
   */
  public function convertToUsd(
    decimal $amount,
    string $currency,
    ?Carbon $asOf = null
  ): ConversionResult
  
  /**
   * Devuelve todas las tasas vigentes (caché Redis 1h).
   */
  public function getCurrentRates(): Collection<ExchangeRate>
  
  /**
   * Log de fallos de conversión (para auditoría).
   */
  public function logConversionError(
    string $currency,
    ?Throwable $exception = null
  ): void
}
```

**DTO Output:**
```php
class ConversionResult {
  public decimal $amountUsd;
  public decimal $rate;
  public string $rateSource;
  public Carbon $rateDate;
  public bool $isOutdated;      // true si > 24h
  public ?string $warningMessage;
}
```

**Lógica:**
```
1. Si currency = USD → return amount sin conversión

2. Buscar tasa en exchange_rates:
   SELECT * FROM exchange_rates 
   WHERE currency_code = 'EUR'
   ORDER BY effective_at DESC LIMIT 1

3. Si tasa > 24h:
   → log warning
   → usar de todas formas (fallback suave)

4. Si moneda no existe:
   → throw CurrencyNotAvailableException

5. Calcular: amountUsd = amount * rate
```

**Tests Mínimos:**
```php
test('convierte correctamente a USD');
test('devuelve warningMessage si tasa > 24h');
test('throw si moneda no existe');
test('caché getCurrentRates 1h');
```

---

### 3️⃣ Migración DB (1 día)

**Archivo:** `database/migrations/2026_08_28_XXXXXX_add_price_estimation_columns.php`

```php
Schema::table('supplier_material_proposal_lines', function (Blueprint $table) {
  $table->decimal('estimated_price_usd', 18, 4)
    ->nullable()
    ->after('unit_price_usd')
    ->comment('Precio estimado en USD (promedio histórico o último cotizado)');
  
  $table->enum('estimated_price_source', ['historical_avg', 'last_quoted'])
    ->nullable()
    ->after('estimated_price_usd')
    ->comment("De dónde vino EST: 'historical_avg' o 'last_quoted'");
  
  $table->decimal('variation_percent', 6, 2)
    ->nullable()
    ->after('estimated_price_source')
    ->comment('Porcentaje de variación: ((PROP - EST) / EST) × 100');
  
  $table->enum('variation_direction', ['increase', 'decrease', 'stable'])
    ->nullable()
    ->after('variation_percent')
    ->comment("'increase' si >5%, 'decrease' si <-5%, 'stable' si ±5%");
  
  // Índice para reportes de variación
  $table->index('variation_percent', 'idx_smpl_variation_percent');
});
```

**Reglas DB:**
- `estimated_price_usd` puede ser NULL (producto nuevo sin histórico)
- `variation_percent` se calcula como: `((unit_price_usd - estimated_price_usd) / estimated_price_usd) * 100`
- `variation_direction` se setea AUTOMÁTICAMENTE por Observer

**Rollback Plan:** Si falla, DROP columnas (simple, reversible)

---

### 4️⃣ Config APP (0.5 días)

**Archivo Nuevo:** `config/pricing.php`

```php
return [
  'price_estimation' => [
    'historical_months' => env('PRICE_HISTORICAL_MONTHS', 6),
    'fallback_to_last_quoted' => true,
  ],
  
  'variation_alert' => [
    'threshold_percent' => env('PRICE_VARIATION_THRESHOLD', 25),
    'enable_alerts' => env('PRICE_ALERTS_ENABLED', true),
  ],
  
  'currency' => [
    'base' => 'USD',
    'local' => env('LOCAL_CURRENCY', 'VES'),
    'supported' => ['USD', 'EUR', 'BRL', 'MXN', 'VES'],
  ],
  
  'exchange_rates' => [
    'api_provider' => env('EXCHANGE_RATE_PROVIDER', 'fixer.io'),
    'update_frequency' => 'daily',
    'fallback_to_bcv_scraping' => true,
    'max_age_hours' => 24,
  ],
];
```

**Nota:** Todos los valores deben estar en `.env` o en config, NUNCA hardcoded.

---

### 5️⃣ Model + Observer (1 día)

**Extender:** `app/Models/SupplierMaterialProposalLine.php`

```php
class SupplierMaterialProposalLine extends Model {
  protected $appends = [
    'estimated_price_display',
    'variation_label',
    'variation_badge_color',
  ];
  
  // Getter: formatea EST para UI
  public function getEstimatedPriceDisplayAttribute(): string {
    if ($this->estimated_price_usd === null) return '—';
    return '$' . number_format($this->estimated_price_usd, 2);
  }
  
  // Getter: label amigable "+7.14% vs promedio"
  public function getVariationLabelAttribute(): string {
    if ($this->variation_percent === null) return '—';
    $sign = $this->variation_percent >= 0 ? '+' : '';
    $percent = abs($this->variation_percent);
    $label = $sign . number_format($percent, 2) . '%';
    $source = $this->estimated_price_source === 'historical_avg' 
      ? 'vs promedio' : 'vs último';
    return "$label $source";
  }
  
  // Getter: color para badge (usado por PriceVariationBadge frontend)
  public function getVariationBadgeColorAttribute(): string {
    if ($this->variation_direction === 'increase') return 'danger';
    if ($this->variation_direction === 'decrease') return 'success';
    return 'neutral';
  }
}
```

**Observer:** `app/Observers/SupplierMaterialProposalLineObserver.php`

```php
class SupplierMaterialProposalLineObserver {
  public function creating(SupplierMaterialProposalLine $line) {
    $this->calculateEstimation($line);
  }
  
  public function updating(SupplierMaterialProposalLine $line) {
    // Recalcular si cambió el precio o moneda
    if ($line->isDirty(['unit_price_usd', 'unit_price', 'quote_currency'])) {
      $this->calculateEstimation($line);
    }
  }
  
  private function calculateEstimation(SupplierMaterialProposalLine $line): void {
    // Obtener EST del servicio
    $est = app(PriceEstimationService::class)->getEstimatedPrice(
      $line->catalog_product_id,
      $line->proposal->supplier_code,
      monthsBack: config('pricing.price_estimation.historical_months')
    );
    
    if ($est === null) {
      $line->estimated_price_usd = null;
      $line->estimated_price_source = null;
      $line->variation_percent = null;
      $line->variation_direction = null;
      return;
    }
    
    // Guardar EST
    $line->estimated_price_usd = $est->value;
    $line->estimated_price_source = $est->source;
    
    // Calcular variación
    $line->variation_percent = (
      ($line->unit_price_usd - $est->value) / $est->value
    ) * 100;
    
    // Determinar dirección
    $threshold = 5; // ±5% = estable
    if ($line->variation_percent > $threshold) {
      $line->variation_direction = 'increase';
    } elseif ($line->variation_percent < -$threshold) {
      $line->variation_direction = 'decrease';
    } else {
      $line->variation_direction = 'stable';
    }
  }
}
```

**Registrar Observer:** En `app/Providers/AppServiceProvider.php`
```php
SupplierMaterialProposalLine::observe(SupplierMaterialProposalLineObserver::class);
```

---

### 6️⃣ Tests Unitarios + Feature (1.5 días)

**Archivo:** `tests/Unit/Services/PriceEstimationServiceTest.php`

```php
class PriceEstimationServiceTest extends TestCase {
  test('calcula promedio histórico últimos 6 meses', function () {
    // Setup: 12 precios en product_price_history
    // Assert: EST = promedio últimos 6
  });
  
  test('fallback a último precio si sin histórico', function () {
    // Setup: Sin product_price_history, SÍ catalog_product_suppliers
    // Assert: EST = last_quoted_price_usd
  });
  
  test('devuelve null si sin histórico ni último', function () {
    // Setup: Producto nuevo sin nada
    // Assert: getEstimatedPrice() returns null
  });
  
  test('batch estimates sin n+1 queries', function () {
    // Setup: 10 líneas de propuesta
    // Assert: Máximo 2 queries (1 price_history, 1 suppliers)
  });
}
```

**Archivo:** `tests/Unit/Services/CurrencyServiceTest.php`

```php
class CurrencyServiceTest extends TestCase {
  test('convierte EUR a USD correctamente', function () {
    // Setup: ExchangeRate EUR = 1.10
    // Assert: 100 EUR = 110 USD
  });
  
  test('USD a USD devuelve mismo monto', function () {
    // Assert: convertToUsd(100, 'USD') = 100
  });
  
  test('devuelve warningMessage si tasa > 24h', function () {
    // Setup: ExchangeRate.updated_at > 24h
    // Assert: result.isOutdated = true, result.warningMessage set
  });
  
  test('throw si moneda no soportada', function () {
    // Setup: currency = 'XYZ' (no existe)
    // Assert: throws CurrencyNotAvailableException
  });
  
  test('getCurrentRates() caché Redis 1h', function () {
    // Assert: primera llamada ≈ 10ms, segunda ≈ 1ms (caché)
  });
}
```

**Archivo:** `tests/Feature/SupplierProposalEstimationTest.php`

```php
class SupplierProposalEstimationTest extends TestCase {
  test('al crear propuesta, EST se calcula automáticamente', function () {
    $proposal = SupplierMaterialProposal::factory()
      ->has(SupplierMaterialProposalLine::factory(3))
      ->create();
    
    foreach ($proposal->items as $item) {
      $this->assertNotNull($item->estimated_price_usd);
      $this->assertNotNull($item->variation_percent);
      $this->assertNotNull($item->variation_direction);
    }
  });
  
  test('variation_percent se calcula correctamente', function () {
    $item = new SupplierMaterialProposalLine([
      'unit_price_usd' => 100,
      'estimated_price_usd' => 80,
    ]);
    
    $expected = ((100 - 80) / 80) * 100; // 25%
    $this->assertEquals($expected, $item->variation_percent);
  });
  
  test('variation_direction: increase >5%', function () {
    $item = new SupplierMaterialProposalLine([
      'variation_percent' => 7.5,
    ]);
    
    $this->assertEquals('increase', $item->variation_direction);
  });
  
  test('variation_direction: decrease <-5%', function () {
    $item = new SupplierMaterialProposalLine([
      'variation_percent' => -6.2,
    ]);
    
    $this->assertEquals('decrease', $item->variation_direction);
  });
  
  test('variation_direction: stable ±5%', function () {
    $this->assertEquals('stable', (new SupplierMaterialProposalLine(['variation_percent' => 3.2]))->variation_direction);
    $this->assertEquals('stable', (new SupplierMaterialProposalLine(['variation_percent' => -4.1]))->variation_direction);
  });
}
```

**Coverage Mínimo:** 85%

---

## Checklist Final

### Backend Dev
- [ ] PriceEstimationService + tests
- [ ] CurrencyService + tests
- [ ] Migración DB (create + rollback test)
- [ ] config/pricing.php
- [ ] SupplierMaterialProposalLine extendido
- [ ] Observer registrado
- [ ] Feature tests pasando
- [ ] Documentación (docstrings + README)

### Code Quality
- [ ] Sin n+1 queries (verificar con debugbar/logs)
- [ ] Exchange rates validadas < 24h
- [ ] Logging de errores de conversión
- [ ] Config sin hardcodes

### Review
- [ ] Code review (peer)
- [ ] Tests en CI/CD pasando
- [ ] Deploy a staging
- [ ] Smoke test: crear propuesta, verificar EST + variación

---

## Output Esperado

**PR con:**
- Servicios (PriceEstimation + Currency)
- Migración DB
- Config APP
- Model + Observer
- Tests (unit + feature)
- Documentación

**Merge a:** `develop`  
**Bloquea:** Tarea #2 (API Resource enriquecido)

---

## Notas Críticas

⚠️ **Esta tarea es el cuello de botella** → si se atrasa, todo se atrasa  
⚠️ **Tests obligatorios** → no skippear  
⚠️ **Config, no hardcode** → parametrizar todo  
⚠️ **Batch estimates** → evitar N+1 queries  

---

**Documento:** TAREA-1-INFRAESTRUCTURA-PRECIOS.md  
**Versión:** 1.0 | **Fecha:** 2026-08-28  
**Status:** 🟢 LISTA PARA EMPEZAR
