# Plan de Implementación: EST vs PROP (Precios Estimados vs Propuestos)

**Versión:** 1.0  
**Fase:** 3.1-3.2 (del Plan Maestro 90 días)  
**Realismo:** ✅ Funcional sin refactorización mayor  
**Dependencias:** Catálogo maestro + Product price history ya implementados  
**Riesgos:** Multimoneda necesita formalización (actualmente informal)

---

## 1. PROBLEMA Y CONTEXTO

### ¿Qué queremos resolver?
Mostrar en múltiples vistas (Propuestas, Catálogo, Analistas, Procura) la **diferencia entre precio estimado (EST) y propuesto (PROP)**, con análisis de:
- Aumento/descenso
- % de variación
- Impacto por proveedor
- Histórico por producto

### Estado actual
✅ **Ya existe:**
- `catalog_products` → catálogo maestro
- `catalog_product_suppliers` → último precio USD por proveedor
- `product_price_history` → log de todos los precios históricos
- `supplier_material_proposal_lines` → líneas de propuestas con precio en múltiples monedas

❌ **Falta:**
- Campo formal de "precio estimado" (EST) por producto/proveedor
- Lógica unificada de cálculo de variación
- Multimoneda formalizada (hay conversión a USD, pero el sistema de monedas es informal)

---

## 2. DEFINICIONES CLAVE

### EST (Estimado)
El precio **referencia o promedio histórico** de un producto con un proveedor específico. Es la base contra la cual medimos si la propuesta (PROP) es cara o barata.

**¿De dónde viene?**
- Opción A (Recomendada): **Promedio ponderado de los últimos N precios** en `product_price_history` por ese proveedor + producto
  - Más representativo de la tendencia real
  - Resiste precios outlier (una cotización excepcionalmente cara no distorsiona el promedio)
  - Fácil de calcular: `SELECT AVG(price_usd) FROM product_price_history WHERE catalog_product_id=X AND supplier_code=Y AND quoted_at >= NOW() - INTERVAL 6 MONTHS`

- Opción B (Simple): Último precio cotizado = `catalog_product_suppliers.last_quoted_price_usd`
  - Más fácil de entender (es "el precio anterior")
  - Pero un precio puntual puede no ser representativo

**→ Recomendación: Opción A + fallback a B si no hay histórico**

### PROP (Propuesto)
El precio que el proveedor cotiza en la propuesta actual.
- Está en `supplier_material_proposal_lines.unit_price_usd` (ya convertido a USD)
- Puede estar en cualquier moneda original (`quote_currency`), pero se normaliza a USD para comparar

### Variación (%)
```
Variación % = ((PROP - EST) / EST) × 100
```
- `> 0`: aumento (rojo/caro)
- `< 0`: descuento (verde/barato)
- `= 0`: igual precio

---

## 3. ARQUITECTURA DE SOLUCIÓN (Realista y Modular)

### 3.1 Backend — Cálculo de EST (centralizado)

**Archivo nuevo:** `app/Services/PriceEstimationService.php`

```php
class PriceEstimationService {
  
  /**
   * Calcula el precio estimado (EST) de un producto por un proveedor.
   * Devuelve el promedio histórico de los últimos 6 meses, o fallback al último precio.
   */
  public function getEstimatedPrice(
    int $catalogProductId,
    string $supplierCode,
    int $monthsBack = 6
  ): ?PriceEstimate {
    // 1. Intenta promedio histórico
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
    
    // 2. Fallback: último precio
    $lastQuoted = DB::table('catalog_product_suppliers')
      ->where('catalog_product_id', $catalogProductId)
      ->where('supplier_code', $supplierCode)
      ->first();
    
    if ($lastQuoted) {
      return new PriceEstimate(
        value: $lastQuoted->last_quoted_price_usd,
        source: 'last_quoted',
        date: $lastQuoted->last_quoted_at,
      );
    }
    
    return null; // Sin histórico ni referencia
  }
}
```

**DTO devuelto:**
```php
class PriceEstimate {
  public decimal $value;      // Precio en USD
  public string $source;      // 'historical_avg' | 'last_quoted'
  public ?int $dataPoints;    // Cuántos precios en el promedio
  public ?int $periodMonths;  // Período del promedio
  public ?string $date;       // Fecha de referencia
}
```

### 3.2 Backend — Variación en propuestas

**Extend** `SupplierMaterialProposalLine` model:

```php
class SupplierMaterialProposalLine extends Model {
  
  protected $appends = ['estimated_price', 'variation_percent', 'variation_direction'];
  
  public function getEstimatedPriceAttribute(): ?PriceEstimate {
    return app(PriceEstimationService::class)->getEstimatedPrice(
      $this->catalog_product_id,
      $this->proposal->supplier_code,
      monthsBack: 6
    );
  }
  
  public function getVariationPercentAttribute(): ?float {
    if (!$this->estimated_price) return null;
    
    return (($this->unit_price_usd - $this->estimated_price->value) 
            / $this->estimated_price->value) * 100;
  }
  
  public function getVariationDirectionAttribute(): string {
    if ($this->variation_percent === null) return 'unknown';
    if ($this->variation_percent > 5) return 'increase'; // >5% = aumento
    if ($this->variation_percent < -5) return 'decrease'; // <-5% = descuento
    return 'stable'; // ±5% = estable
  }
}
```

### 3.3 API — Respuesta enriquecida

Cuando se devuelve `SupplierMaterialProposal` o líneas, incluir:

```json
{
  "id": "PROP-2026-001",
  "supplier_name": "Proveedor ABC",
  "items": [
    {
      "id": 1,
      "material_name": "Tubo acero Ø50mm",
      "quantity": 100,
      "unit": "m",
      "unit_price": 25.50,
      "quote_currency": "USD",
      "unit_price_usd": 25.50,
      
      "estimated_price": {
        "value": 23.80,
        "source": "historical_avg",
        "dataPoints": 12,
        "periodMonths": 6
      },
      
      "variation": {
        "amount_usd": 1.70,
        "percent": 7.14,
        "direction": "increase",
        "label": "+7.14% vs promedio"
      }
    }
  ]
}
```

---

## 4. FRONTEND — VISTAS A ACTUALIZAR

### 4.1 Propuestas de Materiales (ProveedoresRegistrados)

**En `InspectSupplierProposalModal.tsx`:**
- Agregar columna en tabla de materiales: `EST (6m) | PROP | VAR. %`
- Colorear variación: 🟢 (descuento), 🟡 (estable), 🔴 (aumento)
- Tooltip: "Promedio de últimos 6 meses: $X.XX"

```tsx
// Pseudocódigo
{
  key: "variation",
  label: "Variación",
  render: (item) => {
    if (!item.variation) return "—";
    const color = item.variation.direction === 'increase' ? 'danger' 
                : item.variation.direction === 'decrease' ? 'success' : 'neutral';
    return (
      <Badge accent={color}>
        {item.variation.direction === 'increase' ? '↑' : '↓'} 
        {item.variation.percent.toFixed(1)}%
      </Badge>
    );
  }
}
```

### 4.2 Catálogo Maestro (CatalogSection)

**En grid/tabla de productos:**
- Nuevo card con "Proveedor | Último precio | EST (6m) | VAR. %"
- Al expandir proveedor: histórico de precios últimos 12 meses (sparkline)

### 4.3 Detalle de Propuesta (Analistas)

**Nueva sección:** "Análisis de Precios"
- Tabla: Producto | Cant. | EST | PROP | VAR. | Impacto $
- Total estimado vs. total propuesto
- Ahorro/sobrecompra total

```
Total Estimado:   $10,500
Total Propuesto:  $11,250
Diferencia:       +$750 (+7.1%)
```

### 4.4 Evaluación Comparativa (Procura)

**En tabla de propuestas comparadas:**
- Columnas: Proveedor | Cant. | Precio unit. | EST | VAR. % | Total
- Ordenable por variación (para identificar "outliers")

---

## 5. MANEJO DE MULTIMONEDA (Formalización)

### Estado actual (Informal)
- Hay conversión a USD en `supplier_material_proposal_lines`
- No hay validación clara de tasas de cambio
- Finanzas usa BS pero no hay conversión formalizada

### Propuesta (Realista y Modular)

#### 5.1 Tabla `exchange_rates` (ya existe, revisar)
```sql
id | currency_code | rate_to_usd | source | effective_at | created_at
-- | USD | 1.000000 | system | 2026-08-28 | 2026-08-28
-- | EUR | 1.102000 | api | 2026-08-28 | 2026-08-28
-- | BRL | 0.200000 | api | 2026-08-28 | 2026-08-28
```

#### 5.2 Conversión normalizada
**Regla simple:**
1. Toda cotización en cualquier moneda → convertir a USD usando `exchange_rates`
2. Guardar ambas: precio original + precio en USD + fx_rate snapshot
3. Para análisis: siempre comparar en USD (la moneda universal)
4. Para reportes de Finanzas: USD → BS usando tasa del día del reporte

#### 5.3 Backend — Servicio de conversión
```php
class CurrencyService {
  public function convertToUsd(
    decimal $amount,
    string $currency,
    ?Carbon $asOf = null
  ): ConversionResult {
    if ($currency === 'USD') return $amount;
    
    $rate = ExchangeRate::where('currency_code', $currency)
      ->where('effective_at', '<=', $asOf ?? now())
      ->latest('effective_at')
      ->first();
    
    if (!$rate) throw new CurrencyNotAvailableException($currency);
    
    return new ConversionResult(
      amountUsd: $amount * $rate->rate_to_usd,
      rate: $rate->rate_to_usd,
      rateSource: $rate->source,
      rateDate: $rate->effective_at,
    );
  }
}
```

#### 5.4 Config APP (centralizar monedas)
```php
// config/currencies.php
return [
  'base' => 'USD',  // Moneda de análisis
  'local' => 'VES', // Moneda para reportes Finanzas
  'supported' => ['USD', 'EUR', 'BRL', 'MXN', 'VES'],
  'rounding' => 2,
  'rate_sources' => ['fixer.io', 'system', 'manual'],
];
```

---

## 6. MODELO DE DATOS PROPUESTO (Mínimo, sin migración grande)

### Option A (Recomendada): Sin tabla nueva
**Ventaja:** Cero migración, se calcula on-the-fly.
- EST se calcula en `PriceEstimationService` leyendo `product_price_history`
- Variación se calcula en el modelo como `Appended Attribute`
- Todo en código, nada en base de datos

**Desventaja:** Performance si hay millones de registros (pero se cachea con Redis si es necesario).

### Option B: Desnormalizar EST
**Si performance es crítica:**
```sql
ALTER TABLE supplier_material_proposal_lines
ADD COLUMN estimated_price_usd DECIMAL(18, 4) NULL,
ADD COLUMN estimated_price_source VARCHAR(50),
ADD COLUMN variation_percent DECIMAL(6, 2) NULL,
ADD COLUMN variation_direction ENUM('increase', 'decrease', 'stable');
```
**Al crear/actualizar propuesta:** Calcular EST una sola vez + guardar.  
**Desventaja:** Si EST cambia (ej. nuevo precio entra al histórico), debe recalcularse.

**→ Recomendación: Empezar con Option A, migrar a B si performance lo requiere.**

---

## 7. CRONOGRAMA REALISTA

| Tarea | Duración | Dependencias |
|-------|----------|--------------|
| 1. Formalizar multimoneda (Config + CurrencyService) | 2–3 días | Ninguna |
| 2. PriceEstimationService + tests | 2–3 días | Task 1 |
| 3. Extender SupplierMaterialProposalLine (Appended attrs) | 1–2 días | Task 2 |
| 4. API enriquecida (serializers/resources) | 1–2 días | Task 3 |
| 5. Frontend: InspectSupplierProposalModal | 2–3 días | Task 4 |
| 6. Frontend: CatalogSection + Procura | 2–3 días | Task 4 |
| 7. Frontend: Analistas detail (Análisis de Precios) | 2–3 días | Task 4 |
| 8. Tests + refinamiento | 2–3 días | Todas |
| **Total** | **15–23 días** | — |

**Hito realista:** 2–3 semanas una persona, 1 semana si hay paralelismo frontend/backend.

---

## 8. INTEGRACIÓN CON FASE 5 (Análisis de Precios)

Este plan **alimenta directamente** Fase 5 de inflación:
- El histórico ya trazado en `product_price_history` con variaciones
- Las variaciones calculadas aquí → insumo para gráficos de tendencia
- Config centralizado de "alerta de precio" (ej. >25% sobre promedio) → usa `variation_percent`

**No requiere refactor:** La Fase 5 lee de aquí tal cual.

---

## 9. CHECKLIST DE IMPLEMENTACIÓN

### Backend
- [ ] Formalizar `exchange_rates` + validar tasas actualizadas
- [ ] Crear `PriceEstimationService` + tests
- [ ] Crear `CurrencyService` + conversiones
- [ ] Config APP: agregar `currencies` + `price_thresholds`
- [ ] Extender `SupplierMaterialProposalLine` con appended attributes
- [ ] Resource/serializer enriquecido
- [ ] Endpoint de revisión: GET `/supplier-proposals/{id}` con variaciones

### Frontend
- [ ] Badge/icon componente para variación (↑ rojo / ↓ verde / ~ gris)
- [ ] Actualizar `InspectSupplierProposalModal` (tabla de materiales)
- [ ] Actualizar `CatalogSection` (histórico sparkline)
- [ ] Crear sección "Análisis de Precios" en Analistas
- [ ] Actualizar Procura (tabla comparada)

### Testing
- [ ] Unit tests: `PriceEstimationService` (con/sin histórico, fallback)
- [ ] Unit tests: `CurrencyService` (conversiones, tasas faltantes)
- [ ] Integration: propuesta → variación end-to-end
- [ ] QA visual: colores, tooltips, responsive

---

## 10. RIESGOS Y MITIGACIÓN

| Riesgo | Impacto | Mitigación |
|--------|---------|-----------|
| Tasa de cambio desactualizada → precios incorrectos | Alto | Validar `exchange_rates.updated_at` < 24h; alerta si está vieja |
| Histórico vacío para producto nuevo → EST = NULL | Medio | Mostrar "—" en UI; no bloquea propuesta; cae a "last_quoted" si existe |
| Performance si hay millones de propuestas | Medio | Caché Redis de EST (TTL 24h); migrar a desnormalización si benchmark falla |
| Multimoneda informal rompe análisis | Alto | **Crítico:** formalizar en primer sprint; validar que toda cotización tiene `fx_rate_snapshot` |

---

## 11. DEPENDENCIAS EXTERNAS Y REFERENCIAS

- `product_price_history` debe estar poblada y trazable
- `exchange_rates` debe actualizarse diariamente (API o manual)
- Catálogo maestro debe estar consolidado (sin duplicados)

---

## Siguiente paso

**Sesión de alineación:**
1. ¿Option A (calcular on-the-fly) o B (desnormalizar)?
2. ¿Período histórico para EST: 3, 6 o 12 meses?
3. ¿Cómo se actualiza `exchange_rates`? (API automática, cron, manual)
4. ¿Threshold de "alerta de precio" en CONFIG? (ej. >25%)

---

**Versión:** 1.0 | **Fecha:** 2026-08-28 | **Autor:** Plan Maestro Fase 3.1
