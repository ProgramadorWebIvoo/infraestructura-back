# 🚀 FASE 3.1: MVP SIMPLIFICADO
## Propuestas Mejoradas (SIN Multimoneda, Escalable)

**Estado:** 🟢 LISTO PARA DESARROLLAR  
**Enfoque:** MVP primero, multimoneda después  
**Duración realista:** 8 días (1 dev) o 5 días (2 devs)

---

## 🎯 DECISIÓN: ESCALAR GRADUALMENTE

### ❌ Qué NO hacemos ahora (FUTURE)
- Multimoneda (USD, EUR, BRL, etc)
- API del BCV / Fixer.io
- Conversión automática de tasas
- Web scraping

### ✅ Qué SÍ hacemos ahora (MVP)
- TODO en USD (moneda única)
- PriceEstimationService simple (promedio histórico)
- Variación automática (EST vs PROP)
- Tabla mejorada mostrando variación
- Histórico de proveedores básico

### 🔮 Escalar después (FASE X)
- Agregar multimoneda
- Integrar BCV / Fixer
- Conversión de tasas
- Reportes en VES para Finanzas

---

## 📋 FASE 3.1 MVP: 3 SPRINTS (8 DÍAS)

### SPRINT 1: Backend — Servicios Simples (3 DÍAS)

#### Tarea 3.1.1-A: SupplierProposalImportService Refactor (1 día)
**Ubicación:** `app/Services/SupplierProposalImportService.php`

**Cambios:**
- Al importar propuesta, guardar en `product_price_history`
- Asumir todas las propuestas son en USD (por ahora)
- NO validar tasa de cambio (simplificar)
- NO convertir monedas (todo USD)

```php
public function importProposal($data) {
  $proposal = SupplierMaterialProposal::create([
    'supplier_code' => $data['supplier_code'],
    'project_id' => $data['project_id'],
    // ... otros campos
    'quote_currency' => 'USD', // Asumir USD
  ]);

  foreach ($data['items'] as $item) {
    $line = SupplierMaterialProposalLine::create([
      'supplier_material_proposal_id' => $proposal->id,
      'catalog_product_id' => $item['catalog_product_id'],
      'unit_price' => $item['price'],
      'unit_price_usd' => $item['price'], // Sin conversión por ahora
      'quantity' => $item['quantity'],
      'quote_currency' => 'USD',
      // ... otros campos
    ]);

    // Guardar snapshot en histórico
    ProductPriceHistory::create([
      'catalog_product_id' => $item['catalog_product_id'],
      'supplier_code' => $data['supplier_code'],
      'supplier_material_proposal_line_id' => $line->id,
      'price_usd' => $item['price'],
      'original_currency' => 'USD',
      'original_price' => $item['price'],
      'fx_rate_to_usd' => 1.0, // Sin conversión, rate = 1
      'fx_rate_source' => 'usd_only',
      'quoted_at' => now(),
    ]);
  }
}
```

**Tests:**
- Import propuesta → product_price_history poblado
- Múltiples líneas → todas guardadas
- Verificar USD asumido

**Output:** PR `feature/3.1.1-import-service-simple`

**Duration:** 1 día

---

#### Tarea 3.1.1-B: Config APP (0.5 día)
**Ubicación:** `config/pricing.php` (nuevo)

```php
return [
  'price_estimation' => [
    'historical_months' => 6,
    'fallback_to_last_quoted' => true,
  ],
  'currency' => [
    'base' => 'USD', // MVP: solo USD
    'supported' => ['USD'], // Escalable: agregar después
  ],
  'variation_alert' => [
    'threshold_percent' => 25,
    'enable_alerts' => true,
  ],
];
```

**Output:** PR `feature/3.1.1-config-app`

**Duration:** 0.5 día

---

#### Tarea 3.1.1-C: PriceEstimationService Simple (1.5 días)
**Ubicación:** `app/Services/PriceEstimationService.php` (nuevo)

```php
class PriceEstimationService {
  /**
   * Calcula EST (precio estimado) de un producto por proveedor.
   * Promedio de últimos 6 meses en product_price_history.
   */
  public function getEstimatedPrice(
    int $catalogProductId,
    string $supplierCode,
    int $monthsBack = 6
  ): ?PriceEstimate {
    $avgPrice = DB::table('product_price_history')
      ->where('catalog_product_id', $catalogProductId)
      ->where('supplier_code', $supplierCode)
      ->where('quoted_at', '>=', now()->subMonths($monthsBack))
      ->avg('price_usd');

    if ($avgPrice) {
      return new PriceEstimate(
        value: $avgPrice,
        source: 'historical_avg',
        dataPoints: $count,
        periodMonths: $monthsBack,
      );
    }

    // Fallback: último precio
    $lastQuoted = DB::table('catalog_product_suppliers')
      ->where('catalog_product_id', $catalogProductId)
      ->where('supplier_code', $supplierCode)
      ->first();

    if ($lastQuoted) {
      return new PriceEstimate(
        value: $lastQuoted->last_quoted_price_usd,
        source: 'last_quoted',
      );
    }

    return null;
  }
}
```

**DTOs:**
- `PriceEstimate.php` (value, source, dataPoints, periodMonths)

**Tests:**
- Promedio 6 meses correcto
- Fallback a último precio
- NULL si sin histórico
- Sin n+1 queries (batch)

**Output:** PR `feature/3.1.1-price-service`

**Duration:** 1.5 días

---

#### SPRINT 1 MERGE (0.5 día)
Code review + merge a develop. **BLOCKER para Sprint 2.**

**Output:** 3 PRs merged, backend ready

---

### SPRINT 2: DB + Model + API (3 DÍAS)

#### Tarea 3.1.2-A: Migración DB (0.5 día)
**Ubicación:** `database/migrations/2026_08_29_XXXXXX_add_price_estimation_columns.php`

```sql
ALTER TABLE supplier_material_proposal_lines ADD (
  estimated_price_usd DECIMAL(18,4) NULL,
  estimated_price_source ENUM('historical_avg', 'last_quoted') NULL,
  variation_percent DECIMAL(6,2) NULL,
  variation_direction ENUM('increase', 'decrease', 'stable') NULL
);

CREATE INDEX idx_smpl_variation_percent 
  ON supplier_material_proposal_lines(variation_percent);
```

**Output:** PR `feature/3.1.2-migration`

**Duration:** 0.5 día

---

#### Tarea 3.1.2-B: Model + Observer (1 día)
**Ubicación:** `app/Models/` + `app/Observers/`

**Extender SupplierMaterialProposalLine:**
```php
class SupplierMaterialProposalLine extends Model {
  protected $appends = [
    'estimated_price_display',
    'variation_label',
    'variation_badge_color',
  ];

  public function getEstimatedPriceDisplayAttribute(): string {
    if (!$this->estimated_price_usd) return '—';
    return '$' . number_format($this->estimated_price_usd, 2);
  }

  public function getVariationLabelAttribute(): string {
    if (!$this->variation_percent) return '—';
    $sign = $this->variation_percent >= 0 ? '+' : '';
    return $sign . number_format($this->variation_percent, 2) . '%';
  }

  public function getVariationBadgeColorAttribute(): string {
    if ($this->variation_direction === 'increase') return 'danger';
    if ($this->variation_direction === 'decrease') return 'success';
    return 'neutral';
  }
}
```

**Crear Observer:**
```php
class PriceEstimationObserver {
  public function creating(SupplierMaterialProposalLine $line) {
    $this->calculateEstimation($line);
  }

  private function calculateEstimation(SupplierMaterialProposalLine $line) {
    $est = app(PriceEstimationService::class)->getEstimatedPrice(
      $line->catalog_product_id,
      $line->proposal->supplier_code,
      monthsBack: config('pricing.price_estimation.historical_months')
    );

    if (!$est) return;

    $line->estimated_price_usd = $est->value;
    $line->estimated_price_source = $est->source;
    $line->variation_percent = (($line->unit_price_usd - $est->value) / $est->value) * 100;

    $threshold = 5;
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

**Registrar Observer:**
```php
// app/Providers/AppServiceProvider.php
SupplierMaterialProposalLine::observe(PriceEstimationObserver::class);
```

**Output:** PR `feature/3.1.2-model-observer`

**Duration:** 1 día

---

#### Tarea 3.1.2-C: API Resource (1 día)
**Ubicación:** `app/Http/Resources/SupplierMaterialProposalLineResource.php`

```php
class SupplierMaterialProposalLineResource extends JsonResource {
  public function toArray($request) {
    return [
      'id' => $this->id,
      'material_name' => $this->catalogProduct?->name ?? $this->custom_product_name,
      'quantity' => $this->quantity,
      'unit' => $this->unit,
      'unit_price' => $this->unit_price,
      'unit_price_usd' => $this->unit_price_usd,

      // NUEVO
      'estimated_price_usd' => $this->estimated_price_usd,
      'estimated_price_source' => $this->estimated_price_source,
      'variation_percent' => $this->variation_percent,
      'variation_direction' => $this->variation_direction,
      'variation_label' => $this->variation_label,
      'variation_badge_color' => $this->variation_badge_color,
      'estimated_price_display' => $this->estimated_price_display,
    ];
  }
}
```

**Output:** PR `feature/3.1.2-api-resource`

**Duration:** 1 día

---

#### SPRINT 2 MERGE (0.5 día)
Code review + merge. API ready para frontend.

**Output:** 3 PRs merged

---

### SPRINT 3: Frontend + QA (2 DÍAS)

#### Tarea 3.1.3-A: Frontend — Tabla Mejorada (1 día)
**Ubicación:** Frontend (infraestructura)

**Actualizar InspectSupplierProposalModal:**
- Agregar columnas: EST | PROP | VAR%
- Usar PriceVariationBadge (componente genérico)
- Tooltips: "Promedio 6 meses"

**Actualizar SupplierProposalsList:**
- Grid de proyectos (lo que ya hicimos) ✅
- Al abrir modal de propuestas, mostrar EST/Variación ✅

**Output:** PR `feature/3.1.3-frontend-table`

**Duration:** 1 día

---

#### Tarea 3.1.3-B: QA End-to-End (1 día)
- Feature test: import propuesta → EST calcula → UI muestra
- Performance: sin n+1 queries
- UI visual: colores correctos en badget

**Output:** PR `test/3.1-qa-complete`

**Duration:** 1 día

---

## 📊 CRONOGRAMA MVP (8 DÍAS)

| Sprint | Duración | Output | Blocker? |
|--------|----------|--------|----------|
| **1: Backend** | 3.5 días | 3 PRs (importador, config, service) | ✅ |
| **2: DB + API** | 2.5 días | 3 PRs (migración, model, resource) | ✅ |
| **3: Frontend** | 2 días | 2 PRs (UI, QA) | — |
| **TOTAL** | **~8 días** | **8 PRs merged** | **MVP COMPLETO** |

---

## 🎯 MVP ENTREGA

**Al terminar Día 8, usuario VE:**
- ✅ Propuestas muestran "EST (6m) | PROP | VAR%"
- ✅ Badges: 🔴 aumento, 🟢 descuento, 🟡 estable
- ✅ Tooltips: "Promedio 6 meses: $X"
- ✅ Grid de proyectos funciona
- ✅ Histórico de precios poblado automáticamente

**Backend trazable:**
- ✅ product_price_history con datos correctos
- ✅ Observer calcula on-write
- ✅ Sin n+1 queries
- ✅ Tests >85% coverage

---

## 🔮 ESCALAR DESPUÉS (FUTURE TASKS)

### Multimoneda (Fase X)
```
1. CurrencyService (calcula tasas)
2. API BCV / Fixer integration
3. Agregar columna exchange_rates
4. Refactor importador para convertir
5. Reportes en VES para Finanzas
```

### Histórico de Proveedores (Fase 3.1.3)
```
1. ContractorHistoryService
2. Vista consolidada con 12 meses
3. Gráficos de tendencia
```

### Presupuesto (Fase 3.2)
```
1. ProjectBudget model
2. Semáforo presupuestario
```

### Finanzas (Fase 3.3)
```
1. Payment model
2. Notificaciones
```

---

## 📌 COMMITS ORDENADOS (8 TOTAL)

1. `feat: Refactor SupplierProposalImportService para guardar en product_price_history`
2. `feat: Config APP para pricing`
3. `feat: PriceEstimationService (MVP sin multimoneda)`
4. `feat: Migración DB para columnas de estimación y variación`
5. `feat: Model Observer para cálculo automático de EST y variación`
6. `feat: API Resource enriquecido con datos de precio`
7. `feat: Tabla mejorada de propuestas con EST/Variación en UI`
8. `test: QA end-to-end Fase 3.1 MVP — propuestas con variación`

---

## ✅ POR QUÉ ESTE ENFOQUE

**MVP Primero (Hoy):**
- ✅ Valor tangible en 8 días
- ✅ Sin complejidad de multimoneda
- ✅ Prepara base para escalar
- ✅ Todo en USD (asunción segura)

**Escalar Después:**
- BCV cuando sea necesario (Fase X)
- Multimoneda cuando Finanzas lo pida
- Histórico completo en iteración posterior

**Resultado:**
- Rápido → Usuario ve valor (propuestas mejoradas)
- Limpio → Sin overhead innecesario
- Flexible → Escala cuando necesite

---

## 🚀 COMENCEMOS

**Dia 1 (Hoy):** Tarea 3.1.1-A  
**Dia 2:** Tarea 3.1.1-B  
**Dia 3:** Tarea 3.1.1-C  
**Dia 4:** Code review + Merge  
**Dia 5:** Tarea 3.1.2-A, B, C (paralelo posible)  
**Dia 6:** Code review + Merge  
**Dia 7:** Tarea 3.1.3-A  
**Dia 8:** Tarea 3.1.3-B  

**LISTO: MVP Fase 3.1 en 8 días.**

---

**Documento:** FASE-3.1-MVP-SIMPLIFICADO.md  
**Estado:** 🟢 LISTO PARA COMENZAR  
**Próximo paso:** Tarea 3.1.1-A mañana
