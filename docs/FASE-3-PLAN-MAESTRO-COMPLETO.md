# 🎯 FASE 3: PLAN MAESTRO COMPLETO
## Proveedores + Finanzas + Presupuesto (Semanas 7–8, ~18 días)

**Fecha creación:** 2026-08-28  
**Status:** 🟢 LISTO PARA EJECUTAR  
**Estructura:** Realista, aplicable, escalable, funcional  
**Problema resuelto:** Desalineación por falta de orden claro

---

## 📋 CONTEXTO: Lo Que Hemos Hecho Hasta Ahora

### ✅ Trabajo Completado (SIN Commits aún)

| Qué | Dónde | Status | Rescatable? |
|-----|-------|--------|------------|
| Grid de proyectos + modal propuestas | Frontend | UI completa | ✅ SÍ |
| Componente ProjectGridCard | Frontend | Componente genérico | ✅ SÍ |
| Plan EST vs PROP | Documentación | Teórico | ✅ SÍ (Fase 3.1.2) |
| Tarea #1 detallada | Documentación | Blueprint | ✅ SÍ (Fase 3.1.2) |
| Auditoría BD + Backend | Documentación | Análisis | ✅ Contexto válido |

### ❌ Problema Identificado
**Desalineación por:**
- Sin commits (trabajo invisible)
- Sin orden claro de Fase 3 completa
- Scope creep (grid de proyectos no estaba en plan maestro)
- Salto a EST/PROP sin completar base

### ✅ Solución: Plan Maestro Fase 3 Completo
Este documento mapea **TODA la fase 3**, integra lo hecho, define orden, y elimina scope creep.

---

## 🏗️ ESTRUCTURA DE FASE 3

```
FASE 3 (Semanas 7–8, ~18 días)
│
├─ 3.1 PROVEEDORES (Propuestas mejoradas)
│  ├─ 3.1.1 Propuestas por producto (3–4 días) → BD + Backend
│  ├─ 3.1.2 Tabla mejorada con EST/Variación (3–4 días) → Backend + Frontend
│  └─ 3.1.3 Histórico de proveedores (4–5 días) → Backend + Frontend
│
├─ 3.2 CONTROL PRESUPUESTARIO (4–5 días) → Backend + Frontend
│  ├─ Semáforo por proyecto
│  └─ Umbrales en CONFIG
│
└─ 3.3 FINANZAS (2–3 días) → Backend + Frontend
   └─ Notificación automática de pago
```

**Total realista:** 18 días (1 dev) o 12 días (2 devs paralelo)

---

## 📌 FASE 3.1: PROVEEDORES (Propuestas Mejoradas)

### 3.1.1: Propuestas por Producto (3–4 días)

**Qué es:** Backend + DB para que una propuesta tenga línea-por-línea con datos estructurados

**¿Está hecho?** ✅ **PARCIALMENTE**
- BD existe (supplier_material_proposal_lines)
- SupplierProposalImportService existe pero está **INCOMPLETO**

**Tareas:**

#### Tarea 3.1.1-A: Completar SupplierProposalImportService (1 día)
**Ubicación:** `app/Services/SupplierProposalImportService.php`

**Qué hacer:**
- Refactorizar para que al importar propuesta, TAMBIÉN:
  - Guarde snapshot en `product_price_history`
  - Calcule `fx_rate_to_usd` de cada línea
  - Valide `exchange_rates` vigentes (< 24h)
- Agregar validación: si tasa > 24h, log warning pero continúa

**Tests:**
- Import propuesta → product_price_history poblado
- Exchange rate vieja → warning logged pero propuesta se guarda
- Múltiples líneas → todas en product_price_history

**Output:** PR con `feature/3.1.1-import-service-refactor`

#### Tarea 3.1.1-B: Crear Config APP (0.5 días)
**Ubicación:** `config/pricing.php` (nuevo)

```php
return [
  'price_estimation' => [
    'historical_months' => env('PRICE_HISTORICAL_MONTHS', 6),
    'fallback_to_last_quoted' => true,
  ],
  'currency' => [
    'base' => 'USD',
    'local' => env('LOCAL_CURRENCY', 'VES'),
    'supported' => ['USD', 'EUR', 'BRL', 'MXN', 'VES'],
  ],
  'exchange_rates' => [
    'max_age_hours' => 24,
    'update_frequency' => 'daily',
  ],
];
```

**Output:** PR con `feature/3.1.1-config-app`

#### Tarea 3.1.1-C: Servicios de Precio (2 días)
**Ubicación:** `app/Services/`

**Crear:**
1. `PriceEstimationService.php` — calcula EST
2. `CurrencyService.php` — convierte a USD

**DTOs (dentro Services por ahora):**
- `PriceEstimate.php` (value, source, dataPoints, periodMonths)
- `ConversionResult.php` (amountUsd, rate, rateDate, isOutdated)

**Tests:** ≥85% coverage

**Output:** PR con `feature/3.1.1-price-services`

---

### 3.1.2: Tabla Mejorada con EST/Variación (3–4 días)

**Qué es:** Agregar columnas + lógica para mostrar EST y variación automáticamente

**¿Está hecho?** ❌ **NO**

**Tareas:**

#### Tarea 3.1.2-A: Migración DB (1 día)
**Ubicación:** `database/migrations/2026_08_29_XXXXXX_add_price_estimation_columns.php`

```sql
ALTER TABLE supplier_material_proposal_lines ADD (
  estimated_price_usd DECIMAL(18,4) NULL,
  estimated_price_source ENUM('historical_avg', 'last_quoted') NULL,
  variation_percent DECIMAL(6,2) NULL,
  variation_direction ENUM('increase', 'decrease', 'stable') NULL
);
CREATE INDEX idx_smpl_variation_percent ON supplier_material_proposal_lines(variation_percent);
```

**Output:** PR con `feature/3.1.2-migration`

#### Tarea 3.1.2-B: Model + Observer (1.5 días)
**Ubicación:** `app/Models/` + `app/Observers/`

**Extender:**
- `SupplierMaterialProposalLine.php` — appended attributes (estimated_price_display, variation_label, variation_badge_color)
- Crear `PriceEstimationObserver.php` — calcula EST al crear/actualizar línea

**Output:** PR con `feature/3.1.2-model-observer`

#### Tarea 3.1.2-C: API Resource (1 día)
**Ubicación:** `app/Http/Resources/SupplierMaterialProposalLineResource.php`

Serializar con:
- `estimated_price_usd`, `estimated_price_source`
- `variation_percent`, `variation_direction`
- `variation_label` (formateado para UI)

**Output:** PR con `feature/3.1.2-api-resource`

#### Tarea 3.1.2-D: Frontend — Tabla Mejorada (1.5 días)
**Ubicación:** Frontend (infraestructura)

**Actualizar:**
- `InspectSupplierProposalModal.tsx` — + columnas EST | PROP | VAR%
- `PriceVariationBadge.tsx` — componente genérico (existe del grid)
- Sorting + filtering por variación

**Output:** PR con `feature/3.1.2-table-ui`

---

### 3.1.3: Histórico de Proveedores (4–5 días)

**Qué es:** Vista consolidada de un proveedor con toda su relación histórica con la empresa

**¿Está hecho?** ❌ **NO**

**Tareas:**

#### Tarea 3.1.3-A: Modelo + Relaciones (1 día)
**Ubicación:** `app/Models/Contractor.php` (extender)

Agregar métodos:
- `priceHistory()` — todos los precios cotizados (join a product_price_history)
- `adjudications()` — todas las adjudicaciones
- `proposalsCount()` — cantidad de propuestas
- `averageDeliveryDays()` — promedio días entrega
- `documentsList()` — archivos adjuntos

**Output:** PR con `feature/3.1.3-contractor-relations`

#### Tarea 3.1.3-B: Service Histórico (1.5 días)
**Ubicación:** `app/Services/ContractorHistoryService.php` (nuevo)

```php
class ContractorHistoryService {
  public function getSummary(Contractor $contractor): ContractorHistorySummary
  public function getPriceHistory(Contractor $contractor, int $months = 12): Collection
  public function getAdjudications(Contractor $contractor): Collection
  public function getPerformanceMetrics(Contractor $contractor): PerformanceMetrics
}
```

**Output:** PR con `feature/3.1.3-history-service`

#### Tarea 3.1.3-C: API Resource (1 día)
**Ubicación:** `app/Http/Resources/ContractorHistoryResource.php` (nuevo)

Serializar con histórico completo (precios, adjudicaciones, documentos).

**Output:** PR con `feature/3.1.3-history-resource`

#### Tarea 3.1.3-D: Frontend — Vista Histórico (1–1.5 días)
**Ubicación:** Frontend

**Crear:**
- `SupplierHistoricalDetail.tsx` — vista con tabs (Precios, Adjudicaciones, Documentos)
- Gráfico: Histórico de precios últimos 12 meses
- Tabla: Adjudicaciones con montos

**Output:** PR con `feature/3.1.3-supplier-detail-ui`

---

## 🎯 FASE 3.1 — ROADMAP EJECUTABLE

### Sprint 1 (Días 1–4): Backend — Servicios + DB

| Día | Tarea | Duración | Rama | Blocker? |
|-----|-------|----------|------|----------|
| 1 | 3.1.1-A: Refactor SupplierProposalImportService | 1 día | `feature/3.1.1-import-service-refactor` | ❌ |
| 1 | 3.1.1-B: Config APP | 0.5 días | `feature/3.1.1-config-app` | ❌ |
| 2–3 | 3.1.1-C: PriceEstimationService + CurrencyService | 2 días | `feature/3.1.1-price-services` | ❌ |
| 3–4 | 3.1.2-A: Migración DB | 1 día | `feature/3.1.2-migration` | ✅ BLOCKER |
| 4 | Code Review + Merge todas | 0.5 días | — | ✅ BLOCKER para frontend |

**Output Sprint 1:** Backend ready, 4 PRs merged a develop

### Sprint 2 (Días 5–8): Backend — Models + API

| Día | Tarea | Duración | Rama | Paralelo? |
|-----|-------|----------|------|----------|
| 5 | 3.1.2-B: Model + Observer | 1.5 días | `feature/3.1.2-model-observer` | Con C |
| 5–6 | 3.1.2-C: API Resource | 1 día | `feature/3.1.2-api-resource` | Con B |
| 6–7 | 3.1.3-A: Contractor Relations | 1 día | `feature/3.1.3-contractor-relations` | ❌ |
| 7–8 | 3.1.3-B: History Service | 1.5 días | `feature/3.1.3-history-service` | ❌ |
| 8 | Code Review + Merge | 0.5 días | — | ✅ BLOCKER para frontend |

**Output Sprint 2:** 4 PRs merged, API ready

### Sprint 3 (Días 9–12): Frontend + Final Backend

| Día | Tarea | Duración | Rama | Paralelo? |
|-----|-------|----------|------|----------|
| 9–10 | 3.1.2-D: Frontend Table UI | 1.5 días | `feature/3.1.2-table-ui` | Con C |
| 9–10 | 3.1.3-C: History Resource | 1 día | `feature/3.1.3-history-resource` | Con D |
| 11–12 | 3.1.3-D: Frontend Supplier Detail | 1.5 días | `feature/3.1.3-supplier-detail-ui` | ❌ |
| 12 | QA + Testing end-to-end | 0.5 días | — | ✅ |

**Output Sprint 3:** 3 PRs merged, Fase 3.1 COMPLETA

**Total Fase 3.1:** 12 días (1 dev) o 8 días (2 devs paralelo)

---

## 💰 FASE 3.2: CONTROL PRESUPUESTARIO (4–5 días)

**Qué es:** Semáforo por proyecto (estimado/aprobado/adjudicado/pagado/gasto final)

**Tareas:**

#### 3.2.1: Modelo Budget (1 día)
- Crear tabla: `project_budgets` (project_id, estimado, aprobado, semáforo)
- Model: `ProjectBudget.php`
- Relación: Project hasOne ProjectBudget

#### 3.2.2: Servicio Budget (1.5 días)
- `BudgetCalculationService.php` — calcula estado (estimado/aprobado/adjudicado/pagado)
- Métodos: `getStatus()`, `getVariation()`, `getUsedPercent()`

#### 3.2.3: Resource + Controller (1 día)
- `ProjectBudgetResource.php`
- `ProjectBudgetController.php`

#### 3.2.4: Frontend — Semáforo (1–1.5 días)
- Componente `BudgetSemaphore.tsx` (reutilizable)
- Vista en proyecto: estado presupuestario con colores

**Output Fase 3.2:** 4 PRs, budget visible en UI

---

## 📧 FASE 3.3: FINANZAS (2–3 días)

**Qué es:** Notificación automática de pago al confirmar pago

**Tareas:**

#### 3.3.1: Payment Model (0.5 días)
- Tabla: `payments` (proposal_id, monto, comprobante, estado)
- Model: `Payment.php`

#### 3.3.2: Notification Service (1 día)
- `PaymentNotificationService.php` — envía email/push al confirmar pago
- Usa motor de notificaciones de Fase 1

#### 3.3.3: Controller + Frontend (1–1.5 días)
- `PaymentController@store` → guarda + notifica
- UI: formulario pago con comprobante

**Output Fase 3.3:** 3 PRs, notificaciones funcionando

---

## 🔄 INTEGRANDO LO QUE HEMOS HECHO

### Grid de Proyectos (Frontend)
**Ubicación actual:** Cambios en infraestructura sin commit

**Decisión:** 
- ✅ **MANTENER** — es buena UX
- 📍 **Ubicación en Fase 3:** Es bonus de Fase 3.1.2 (Tabla mejorada)
- 🎯 **Commit:** Incluir en PR de `feature/3.1.2-table-ui`

**No es scope creep porque:**
- Se reutiliza para navegar propuestas
- Facilita acceso a histórico (Fase 3.1.3)
- No bloquea nada

### Plan EST vs PROP
**Ubicación actual:** Documentación (PLAN-EST-vs-PROP-PRECIOS.md)

**Decisión:**
- ✅ **USAR COMO BLUEPRINT** para Tarea 3.1.1-C
- 📍 **Ubicación en Fase 3:** Tarea 3.1.1-C (Servicios de Precio)
- 🎯 **Integración:** Tarea #1 del plan = 3.1.1-C

### Auditorías (BD + Backend)
**Ubicación:** Documentación (diagnósticos)

**Decisión:**
- ✅ **CONTEXTO VÁLIDO** para Fase 3
- 📍 **Uso:** Validar que estructura es rescatable
- 🎯 **Conclusión:** NO refactorizar, solo agregar servicios

---

## 📊 CRONOGRAMA FINAL (18 días realista)

| Fase | Sprint | Duración | Output |
|------|--------|----------|--------|
| **3.1** | 1–3 | **12 días** | Propuestas mejoradas con EST/Variación + Histórico |
| **3.2** | 4 | **4 días** | Presupuesto con semáforo |
| **3.3** | 5 | **2 días** | Notificaciones de pago |
| **QA + Buffer** | — | **~2 días** | Testing end-to-end + fixes |
| **TOTAL** | — | **~18–20 días** | Fase 3 COMPLETA |

**Con 2 devs paralelo (1 backend, 1 frontend):** 10–12 días

---

## ✅ CHECKLIST DE COMMITS

**Fase 3.1 (12 commits, 1 por día):**
1. `feat: Refactor SupplierProposalImportService para guardar en product_price_history`
2. `feat: Config APP para pricing y currencies`
3. `feat: PriceEstimationService y CurrencyService`
4. `feat: Migración DB para columnas de estimación y variación`
5. `feat: Model Observer para cálculo automático de EST y variación`
6. `feat: API Resource enriquecido con datos de precio`
7. `feat: Extender Contractor model con relaciones históricas`
8. `feat: ContractorHistoryService para trazabilidad completa`
9. `feat: ContractorHistoryResource para API`
10. `feat: Tabla mejorada de propuestas con EST/Variación en UI`
11. `feat: Vista histórica de proveedor con gráficos`
12. `test: QA end-to-end Fase 3.1 — propuestas con variación`

**Fase 3.2 (4 commits):**
13. `feat: ProjectBudget model + semáforo de presupuesto`
14. `feat: BudgetCalculationService`
15. `feat: Budget UI component`
16. `test: QA end-to-end Fase 3.2 — presupuesto`

**Fase 3.3 (3 commits):**
17. `feat: Payment model + notificaciones`
18. `feat: PaymentNotificationService`
19. `test: QA end-to-end Fase 3.3 — pagos`

**Total:** 19 commits claros, ordenados, rescatables

---

## 🚨 PROBLEMAS RESUELTOS

| Problema | Solución en Plan |
|----------|-----------------|
| Desalineación por falta de orden | Fase 3 desglosada en 3.1 / 3.2 / 3.3 con tareas claras |
| Scope creep (grid de proyectos) | Incluir como bonus en 3.1.2, no como tarea separada |
| Sin commits (todo en limbo) | 19 commits ordenados, 1 por tarea |
| Confusión sobre dónde va qué | Estructura clara: Services, Models, Controllers, Resources |
| Tareas bloqueantes no claras | Dependencias explícitas por tarea |
| Incertidumbre sobre si es rescatable | Auditoría completa: 7.5/10, NO requiere refactor |

---

## 🎯 PRÓXIMOS PASOS (INMEDIATOS)

### Semana 1: Preparación
1. ✅ **Este plan es la "fuente de verdad"** — todos leen y confirman
2. ✅ **Guardar en memoria** — contexto persiste entre sesiones
3. ✅ **Crear ramas** — (no commits aún, solo prep)
4. ✅ **Setup de DB local** — migraciones listas

### Semana 2: Comienza Sprint 1
1. 🚀 **Tarea 3.1.1-A:** Refactor SupplierProposalImportService
2. 🚀 **Tarea 3.1.1-B:** Config APP
3. 🚀 **Tarea 3.1.1-C:** PriceEstimationService + CurrencyService
4. 🚀 **Tarea 3.1.2-A:** Migración DB
5. ✅ **Merge Sprint 1** — Backend ready

### Semana 3: Sprint 2 + Sprint 3 (Paralelo)
- Backend: 3.1.2-B, 3.1.3-A, 3.1.3-B, 3.1.3-C
- Frontend: 3.1.2-D, 3.1.3-D (paralelo)

### Final: Fase 3.2 + 3.3 + QA
- 4 días presupuesto
- 2 días finanzas
- 2 días QA total

---

## 📌 ESTE ES EL PLAN MAESTRO

**Estado:** 🟢 LISTO PARA EJECUTAR  
**Coherencia:** ✅ RESUELVE DESALINEACIÓN  
**Rescatable:** ✅ TODO LO HECHO SIRVE  
**Realista:** ✅ BASADO EN AUDITORÍA  

**Cuando comiences, este documento es la "fuente de verdad". Todas las decisiones vienen de aquí.**

---

**Documento:** FASE-3-PLAN-MAESTRO-COMPLETO.md  
**Versión:** 1.0 | **Fecha:** 2026-08-28  
**Próximo paso:** Confirmar plan, comenzar Semana 1
