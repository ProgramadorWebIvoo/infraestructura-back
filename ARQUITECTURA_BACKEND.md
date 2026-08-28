# Documento Maestro de Arquitectura — Backend Laravel infraestructura-back

**Fecha:** 2026-08-28  
**Versión:** 1.0  
**Estado:** Análisis Completo

---

## 🎯 Resumen Ejecutivo

El backend Laravel **infraestructura-back** es una aplicación empresarial de gestión de obras/proyectos con integración de IA, multimoneda, y notificaciones push. 

- **Líneas de Código:** ~3,500 PHP (142 archivos en `app/`)
- **Migraciones:** 77 (evolución desde junio 2026)
- **Controllers:** 24 API
- **Services:** 27
- **Models:** 24
- **Tests:** 47 (cobertura ~70%)
- **Peso BD:** ~30+ tablas con relaciones complejas

**Arquitectura:** Layered MVC estándar Laravel + Domain Services + DTOs + Value Objects

---

## 📐 1. ESTRUCTURA ACTUAL

### 1.1 Directorios Clave

```
app/
├── Console/Commands/          # Tareas programadas (pruning, sincronización)
├── DTO/                       # Data Transfer Objects (PriceEstimate.php)
├── Events/                    # Event Broadcasting (NotificationCreated.php)
├── Http/
│   ├── Controllers/Api/       # 24 API Controllers
│   ├── Middleware/            # 12 middleware (CheckRole, RefreshToken, CSP, etc.)
│   ├── Requests/              # 19 Form Requests (validación)
│   └── Resources/             # 3+ JsonResources (ProjectResource, etc.)
├── Models/                    # 24 Eloquent Models
├── Notifications/             # Mail + Push (Expo + WebSocket)
├── Observers/                 # PriceEstimationObserver.php
├── Providers/                 # AppServiceProvider, etc.
├── Rules/                     # 2 validación custom (SsrfSafeUrl, StrongPassword)
└── Services/                  # 27 servicios de negocio

database/
├── migrations/                # 77 migraciones cronológicas
├── seeders/                   # Production seeder + diagnostics
└── factories/                 # Model factories para tests

tests/
├── Feature/                   # 44 tests de integración
└── Unit/                      # 3 tests unitarios

config/
├── app.php                    # Laravel core
├── auth.php                   # Sanctum config
├── permissions.php            # Matriz rol × rutas (SPA)
├── pricing.php                # Price estimation config
├── filesystems.php            # S3 + local storage
└── ...otros (6+ más)
```

### 1.2 Controllers API (24)

| Controller | Responsabilidad | Métodos HTTP | Auth |
|-----------|-----------------|--------------|------|
| **ProjectController** | CRUD de obras, flujos de aprobación | GET, POST, PATCH | Sanctum |
| **ProjectDocumentController** | Planos, hojas de cálculo, fotos (versionado) | GET, POST, DELETE | Sanctum |
| **SupplierProposalController** | Propuestas de proveedores (público + interno) | GET, POST | Mixed |
| **ProjectController::pay()** | Pagos (anticipo/final) | POST | FINANZAS |
| **AIEvaluationController** | Evaluación IA de propuestas | POST | PROCURA |
| **AuthController** | Login, reset, permisos | POST, GET | Mixed |
| **UserController** | CRUD usuarios, roles | GET, POST, PATCH | SUPERADMIN |
| **ContractorController** | Proveedores/contratistas | GET, POST, PATCH | Mixed |
| **MaterialController** | Catálogo de materiales | GET, POST, PATCH | SUPERADMIN |
| **CatalogProductController** | Productos catalogados (normalizado desde items JSON) | GET | Mixed |
| **CatalogCategoryController** | Categorías del catálogo | GET, POST, PATCH | SUPERADMIN |
| **CurrencyController** | Monedas (base, multimoneda MVP) | GET, POST, PATCH | Mixed |
| **ExchangeRateController** | Histórico tasas de cambio USD | GET, POST | SUPERADMIN |
| **AppSettingController** | Configuración app (matriz notificaciones, umbrales) | GET, PATCH | SUPERADMIN |
| **AppNotificationController** | Bandeja interna de alertas persistentes | GET, PATCH, DELETE | Sanctum |
| **ConfigAuditLogController** | Historial de cambios de config | GET | SUPERADMIN |
| **NotificationRuleController** | Matriz rol × acción × canal | GET, PUT | SUPERADMIN |
| **AiConfigController** | Gestión proveedores IA (OpenAI, Anthropic, Gemini) | GET, POST, PATCH, DELETE | SUPERADMIN |
| **PushTokenController** | Tokens para push notifications (Expo) | POST, DELETE | Sanctum |
| **SupplierInvitationController** | Invitaciones a proveedores (token público) | GET, POST | Mixed |
| **DashboardSummaryController** | Agregados ejecutivos (funnels, KPIs) | GET | PRESIDENCIA |
| **AuditLogController** | Historial de acciones (sin paginación, buffer mode) | GET | Sanctum |
| **CustomProductResolutionController** | Reclasificación de productos personalizados | GET, POST | PRESIDENCIA |
| **ModuleController** | Módulos/features disponibles | GET | Sanctum |

### 1.3 Services (27)

**Agrupación por Dominio:**

**Core Business Logic:**
- `ProjectService` — (implícito en ProjectController, considerar extraer)
- `DossierEvaluationService` — Evaluación IA de expedientes (Fase 3)
- `AIEvaluationService` — Orquestador de evaluación de propuestas
- `PriceEstimationService` — Cálculo de EST (estimaciones de precio)
- `ProposalLineNormalizer` — Normaliza JSON de items → líneas estructuradas
- `RejectionService` — Flujo de rechazo (transaccional, parametrizable)
- `SupplierProposalImportService` — Importación de propuestas desde portal público

**Catalog & Marketplace:**
- `CatalogSyncService` — Sincronización de catálogo desde propuestas de proveedores
- `DashboardSummaryService` — Agregados ejecutivos (KPIs, funnels)

**Notifications & Communication:**
- `NotificationDispatcher` — Router de notificaciones (mail, push, email)
- `NotificationRuleResolver` — Resuelve destino de notificación (matriz rol × acción)

**AI Providers (Sub-services):**
- `AiConfigurationService` — CRUD de configuraciones IA
- `AIEvaluationService` — Orquestador de llamadas IA
- `AiUsageAnalyticsService` — Tracking de tokens consumidos
- Providers: `OpenAIProvider`, `AnthropicProvider`, `GeminiProvider`, `BaseAIProvider`, `AIProviderFactory`
- Strategies: `DossierEvaluationStrategy`, `ProposalEvaluationStrategy`, `AbstractEvaluationStrategy`

**Configuration & Infrastructure:**
- `SettingsService` — Lectura/escritura de AppSetting (caché 24h)
- `DocumentStorageService` — S3/local storage para archivos de proyectos
- `ExpoPushService` — Integración con Expo Push Notifications

---

## ⚠️ 2. ANTI-PATTERNS IDENTIFICADOS

### 2.1 Violaciones de Single Responsibility Principle (SRP)

#### ❌ ProjectController es demasiado grande

- **Tamaño:** ~500+ líneas
- **Responsabilidades entrelazadas:**
  - CRUD de proyectos
  - Flujo de estado (revisión, rechazo, renegociación)
  - Importación de propuestas
  - Caché invalidation de IA
  - Sincronización de materiales
  - Auditoría directa

**Impacto:** Difícil de testear, cambios ripple effect.

**Propuesta:** Extraer ProjectStateMachine, ProjectProposalManager, ProjectAuditService

---

#### ❌ DashboardSummaryService hace demasiadas cosas

- **Responsabilidades:**
  - Cálculo de KPIs
  - Agregación por tipo, ubicación, mes
  - Detección de proyectos estancados
  - Transformación de datos en múltiples formatos

**Impacto:** No escalable si cambian requisitos de dashboard. Lógica de agregación + presentación mezcladas.

**Propuesta:** Separar en:
  - `ProjectAggregationService` (cálculos puros)
  - `DashboardTransformer` (formato → presentación)
  - `StalledProjectDetector` (lógica de detección)

---

#### ❌ SupplierProposalImportService con lógica de negocio dispersa

- **Problemas:**
  - Normalización de propuestas
  - Sincronización de catálogo
  - Manejo de errores ad-hoc
  - Auditoría manual

**Propuesta:** Introducir Pipeline pattern:
  - `ImportPipeline` → ValidateStep, NormalizeStep, CatalogSyncStep, AuditStep

---

### 2.2 N+1 Queries

#### ❌ ProjectController::index()

```php
$query = Project::with(['materials', 'proposals', 'payments', 
    'documents' => fn ($q) => $q->latestVersionOnly()])
    ->latest('created_date');
```

- ✅ Eager loading está en lugar, pero:
- ❌ **latestVersionOnly()** scope puede ser ineficiente si hay muchas versiones
- ❌ Sin límite de documentos por proyecto

**Propuesta:**
```php
->with(['documents' => fn ($q) => $q->latestVersionOnly()->limit(100)])
->leftJoin('project_documents', ...)
->select('projects.*', DB::raw('COUNT(DISTINCT documents.id) as doc_count'))
->groupBy('projects.id')
```

---

#### ❌ DashboardSummaryService::getSummary()

```php
$projects = Project::with(['payments', 'proposals'])->get();
// Luego itera y accesa $project->proposals->firstWhere()
```

- ❌ Trae **todos** los proyectos en memoria
- ❌ Propuestas se cargan enteras aunque solo necesita 1 row
- ❌ Pagos se cargan enteros para sum()

**Propuesta:**
```php
// SQL puro o agregaciones en la query
SELECT p.id, COUNT(pr.id) as proposal_count, SUM(pp.amount) as total_payments
FROM projects p
LEFT JOIN project_proposals pr ON pr.project_id = p.id
LEFT JOIN project_payments pp ON pp.project_id = p.id
GROUP BY p.id
```

---

### 2.3 Falta de Validación de Integridad en Migraciones

#### ❌ CHECK constraints incompletos

- **Problema:** `supplier_material_proposal_lines` tiene CHECK para `catalog_product_id OR custom_product_name`
- ❌ Solo se aplica en MySQL, no en SQLite (tests)
- ❌ Sin validación en Model (Eloquent no lo ejecuta)

**Propuesta:**
```php
// En Model
protected function boot() {
    parent::boot();
    static::creating(function ($model) {
        if (is_null($model->catalog_product_id) && is_null($model->custom_product_name)) {
            throw new \InvalidArgumentException('Debe especificar producto o nombre personalizado.');
        }
    });
}
```

---

#### ❌ Índices faltantes en queries críticas

- **Migración más reciente:** `2026_08_28_optimize_supplier_proposals_indexes`
- ❌ Esto debería haberse hecho 2-3 migraciones atrás
- ⚠️ Sin índices en:
  - `project_proposals.project_id` (se crea recientemente)
  - `supplier_material_proposals.submitted_at` (filtro en listados)
  - Composite en `supplier_material_proposal_lines(variation_percent, variation_direction)`

**Propuesta:** Revisar EXPLAIN de queries lentas y agregar índices de forma anticipada.

---

### 2.4 Caching Subóptimo

#### ⚠️ PriceEstimationService usa caché de 24h global

```php
$cacheKey = "price_estimate:{$catalogProductId}:{$supplierCode}:{$monthsBack}";
return Cache::remember($cacheKey, 86400, fn() => $this->computeEstimatedPrice(...));
```

- ✅ La estrategia es correcta (EST cambia lentamente)
- ❌ Sin invalidación cuando hay nuevas cotizaciones en `product_price_history`
- ❌ Sin control granular por producto/proveedor

**Propuesta:** Usar event-driven invalidation:
```php
// En ProductPriceHistory observer
public function created(ProductPriceHistory $model) {
    Cache::tags(['price_estimate', 'product:' . $model->catalog_product_id])
        ->flush();
}
```

---

#### ⚠️ SettingsService caché no versionada

```php
return \Illuminate\Support\Facades\Cache::remember(
    'app_settings',
    86400,
    fn () => AppSetting::all()->keyBy('key')
);
```

- ❌ Cambios en AppSetting no invalidan hasta 24h después
- ❌ No hay mecanismo de publish/broadcast
- ✅ Pero hay `ConfigAuditLog` para auditoría

**Propuesta:** Publicar evento cuando cambia setting:
```php
public function updating() {
    Cache::forget('app_settings');
    event(new AppSettingChanged($this));
}
```

---

### 2.5 Seguridad

#### ✅ SSRF Protection está implementado

- Custom rule `SsrfSafeUrl` en `app/Rules/`
- Usado en AiConfigController

#### ⚠️ Pero sin rate limiting diferenciado por endpoint

- Hay throttle genérico: `throttle:api` (180/min)
- Hay throttle público: `throttle:public-api` (sin especificar, revisar config)
- ❌ Sin rate limiting por usuario real (solo por IP)
- ❌ Sin protección contra brute force en login

**Propuesta:**
```php
Route::post('/login', [AuthController::class, 'login'])
    ->middleware('throttle:login'); // 5 intentos / 15 min por usuario
```

---

### 2.6 Logging & Observability

#### ⚠️ AuditLog está disperso

- 40+ llamadas a `AuditLog::record()` en controllers
- Cada controller/service maneja la auditoría manualmente
- ❌ Sin decorador o aspecto centralizado

**Propuesta:**
```php
// Observer pattern + Middleware
class AuditableObserver {
    public function created(Model $model) {
        if ($model instanceof Auditable) {
            AuditLog::record($model, auth()->user()->role, 'Created', ...);
        }
    }
}
```

---

#### ❌ Sin tracing de requests en operaciones distribuidas

- No hay request_id en logs
- IA async jobs no tienen correlación
- ❌ Difícil debuggear en production

**Propuesta:** Middleware que agrega `X-Request-ID` a todos los logs

---

### 2.7 Transactions & Consistency

#### ✅ Usos correctos de DB::transaction()

- ProjectController::store() las usa
- ProjectController::renegotiateProposal() las usa

#### ⚠️ Pero no uniformes

- SupplierProposalImportService no usa transacción
- Si falla a mitad, queda en estado inconsistente

**Propuesta:** Extraer `TransactionalService` base class

---

## ✅ 3. ESTÁNDARES SOLID PROPUESTOS

### 3.1 Single Responsibility (S)

**Patrón: Servicios pequeños y enfocados**

```
Bad:  GrandeService (200+ líneas, 10+ métodos públicos)
Good: PriceHistoryCalculator (1 método público, ~30 líneas)
      PriceHistoryStorage (1 responsabilidad: persistencia)
      PriceHistoryCache (1 responsabilidad: caché)
```

**Aplicar a:**
1. Dividir ProjectController en:
   - `ProjectCrudService` — CREATE, READ
   - `ProjectApprovalService` — review(), approveInvestment()
   - `ProjectRejectionService` — reject, resubmit
   - `ProjectPaymentService` — pay()
   
2. Dividir DashboardSummaryService en:
   - `ProjectMetricsCalculator`
   - `FunnelBuilder`
   - `StalledProjectDetector`
   - `DashboardPresenter`

3. Dividir SupplierProposalImportService en:
   - `ProposalValidator`
   - `ProposalNormalizer` (ya existe ProposalLineNormalizer)
   - `CatalogMatcher`
   - `AuditRecorder`

---

### 3.2 Open/Closed Principle (O)

**Patrón: Extensible sin modificación**

```
Bad:  if ($provider === 'openai') { ... } elseif ($provider === 'gemini') { ... }
Good: AIProviderFactory::make($provider) → AIProviderInterface
```

**Estado actual:**
- ✅ `AIProviderInterface` + `OpenAIProvider`, `AnthropicProvider`, `GeminiProvider` está bien
- ❌ Pero `AIEvaluationService` tiene if/else para estrategias

**Propuesta:**
```php
// Ya implementado con Strategy pattern
interface EvaluationStrategyInterface {
    public function evaluate(Project $project): EvaluationResult;
}

class DossierEvaluationStrategy implements EvaluationStrategyInterface { ... }
class ProposalEvaluationStrategy implements EvaluationStrategyInterface { ... }

// Factory para seleccionar estrategia
class EvaluationStrategyFactory {
    public static function forDossier(Project $project): EvaluationStrategyInterface
}
```

**Aplicar a:**
1. Estados de Project → State Pattern (ProjectStateInterface)
2. Tipos de notificaciones → Pipeline + Handler Chain
3. Tipos de documentos → Document Type Handler

---

### 3.3 Liskov Substitution Principle (L)

**Patrón: Subclases intercambiables**

```
Bad:  class AdminUser extends User { public function delete() { throw new Exception(); } }
Good: Usar roles en un único User model
```

**Estado actual:**
- ✅ Modelos no violan LSP (no hay "mal" polimorfi smo)
- ⚠️ Pero el cálculo de permisos es por string:
  ```php
  if (in_array($user->role, ['SUPERADMIN', 'ADMIN'])) { ... }
  ```

**Propuesta:**
```php
interface RolePermissionInterface {
    public function can(string $action): bool;
    public function routes(): array;
}

class SuperAdminRole implements RolePermissionInterface { ... }
class AdminRole implements RolePermissionInterface { ... }

// Usar en CheckRole middleware
$role = RoleFactory::make($user->role);
if (!$role->can('projects.approve')) { abort(403); }
```

---

### 3.4 Interface Segregation (I)

**Patrón: Interfaces pequeñas y específicas**

```
Bad:  interface Service { public function read(); public function write(); ... }
Good: interface Reader { public function read(); }
      interface Writer { public function write(); }
```

**Aplicar a:**
1. División de AIProviderInterface:
   ```php
   interface AIModelProvider { public function models(): array; }
   interface AIUsageTracker { public function trackUsage(...): void; }
   interface AIEvaluator { public function evaluate(...): EvaluationResult; }
   ```

2. Division de NotificationDispatcher:
   ```php
   interface NotificationQueuer { public function queue(...): void; }
   interface NotificationSender { public function send(...): bool; }
   interface NotificationLogger { public function log(...): void; }
   ```

---

### 3.5 Dependency Inversion (D)

**Patrón: Depender de abstracciones, no de implementaciones**

```
Bad:  new OpenAIProvider() directamente en el servicio
Good: Inyectar AIProviderInterface a través del constructor
```

**Estado actual:**
- ✅ Los servicios usan inyección de dependencias
- ✅ Controllers inyectan services
- ⚠️ Pero `PriceEstimationService::computeEstimatedPrice()` hace `DB::table()` directamente

**Propuesta:**
```php
class PriceEstimationService {
    public function __construct(
        private PriceHistoryRepository $repository
    ) {}
    
    private function computeEstimatedPrice(...) {
        $this->repository->findHistoricalData(...);
    }
}

interface PriceHistoryRepository {
    public function findHistoricalData(...): Collection;
}

class EloquentPriceHistoryRepository implements PriceHistoryRepository { ... }
```

---

## 📊 4. BD ACTUAL

### 4.1 Tablas Principales

| Tabla | Registros Est. | Claves | Índices | Status |
|-------|----------------|--------|---------|--------|
| **users** | 50-500 | PK(id) | idx_role, idx_status | ✅ Sólida |
| **projects** | 100-1000 | PK(id) | idx_status, idx_type, idx_created_date, FK(selected_contractor_code) | ⚠️ Podría optimizar |
| **project_materials** | 500-5000 | PK(id), FK(project_id) | idx_project_id | ✅ OK |
| **project_proposals** | 500-5000 | PK(id), FK(project_id) | idx_project_id, FK(selected_proposal_id) | ⚠️ Soft delete reciente |
| **supplier_invitations** | 1000+ | PK(token), FK(project_id) | idx_project_id, idx_created_at | ⚠️ Sin expiración automática |
| **supplier_material_proposals** | 5000+ | PK(id), FK(project_id) | idx_project_id, idx_submitted_at (reciente) | ⚠️ Items JSON sin normalización |
| **supplier_material_proposal_lines** | 50000+ | PK(id), FK(proposal_id), FK(catalog_product_id) | Índices recientes | ✅ Normalizado bien |
| **material_catalog** | 5000+ | PK(id) | Índices por categoría | ✅ OK |
| **product_price_history** | 100000+ | PK(id), FK(catalog_product_id) | idx_supplier_code, idx_product_id (reciente) | ⚠️ Crecimiento ilimitado |
| **audit_logs** | 100000+ | PK(id), FK(project_id), FK(user_id) | idx_project_id, idx_user_id | ⚠️ Sin particionamiento |
| **app_notifications** | 500000+ | PK(id), FK(user_id) | idx_user_id, idx_read_at | ⚠️ Sin purga automática |
| **contractors** | 100-500 | PK(code) | idx_status | ✅ OK |
| **currencies** | 5-20 | PK(code) | idx_is_base | ✅ OK |
| **exchange_rates** | 100-1000 | PK(id), FK(currency_id) | idx_currency_id, idx_rate_date | ✅ OK |
| **app_settings** | 50-100 | PK(id) | idx_key | ✅ OK |
| **config_audit_logs** | 1000+ | PK(id) | idx_resource_type, idx_user_id | ✅ OK |

### 4.2 Relaciones

```
User 1--∞ AuditLog
User 1--∞ ProjectPayment
User 1--∞ AppNotification

Project 1--∞ ProjectMaterial
Project 1--∞ ProjectProposal
Project 1--∞ ProjectPayment
Project 1--∞ ProjectDocument
Project 1--∞ AuditLog
Project ∞--1 Contractor (selected_contractor)

Contractor 1--∞ ProjectProposal

ProjectMaterial ∞--1 MaterialCatalog

ProjectProposal 1--1 Project
ProjectProposal ∞--1 Contractor (via contractor_code)

SupplierInvitation 1--∞ SupplierMaterialProposal
SupplierMaterialProposal 1--∞ SupplierMaterialProposalLine
SupplierMaterialProposalLine ∞--1 MaterialCatalog (nullable)
SupplierMaterialProposalLine ∞--1 CustomProductResolution (nullable)

ProductPriceHistory ∞--1 MaterialCatalog
ProductPriceHistory ∞--1 Contractor

CatalogProductSupplier ∞--1 MaterialCatalog
CatalogProductSupplier ∞--1 Contractor

AiConfiguration 1--∞ AiUsageLog
Project 1--∞ AiUsageLog
```

### 4.3 Query Analysis (Slow Queries)

**Posibles problemas (sin EXPLAIN, estimado):**

1. **DashboardSummaryService::getSummary()**
   - Query: `Project::with(['payments', 'proposals'])->get()`
   - Problema: Trae **todas** los proyectos + todas sus propuestas/pagos
   - Solución: Usar GROUP BY + agregaciones SQL

2. **ProjectController::index()**
   - Query: `.with(['documents' => fn($q) => $q->latestVersionOnly()])`
   - Problema: latestVersionOnly() scope sin índice en (project_id, version, deleted_at)
   - Solución: Crear composite index o usar window functions

3. **SupplierProposalImportService::import()**
   - Problema: Itera sobre líneas de propuesta
   - Solución: Usar bulk insert en lugar de loop

---

### 4.4 Data Integrity Issues

#### ❌ Falta foreign key en algunas relaciones:

```php
// projects.selected_proposal_id → project_proposals.id (sin FK!)
// projects.selected_contractor_code → contractors.code (con FK ✅)
```

**Propuesta:** Agregar migración:
```php
Schema::table('projects', function (Blueprint $table) {
    $table->foreign('selected_proposal_id')
        ->references('id')->on('project_proposals')
        ->onDelete('set null');
});
```

#### ❌ Enum sin CHECK constraint suficiente:

```php
enum('status', ['CREADO', 'REVISADO_CIERRE', ...]) // OK en MySQL, no en SQLite
```

**Propuesta:** Agregar validación en Model:
```php
protected function casts(): array {
    return [
        'status' => 'string', // Usar enum PHP 8.1
    ];
}

public function isValidStatus(string $status): bool {
    return in_array($status, ProjectStatus::values(), true);
}
```

---

## 🔌 5. API ENDPOINTS

### 5.1 Público (sin auth)

| Endpoint | Método | Rate Limit | Responsabilidad | Cache |
|----------|--------|-----------|-----------------|-------|
| POST `/login` | POST | public-api | AuthController@login | None |
| POST `/reset-password` | POST | public-api | AuthController@resetPassword | None |
| POST `/contractors` | POST | public-api | ContractorController@registerPublic | None |
| GET `/public/invitations/{token}` | GET | public-api | SupplierInvitationController@publicInfo | None |
| POST `/public/invitations/{token}/proposal` | POST | public-api | SupplierProposalController@store | None |
| POST `/public/invitations/{token}/proposal-image` | POST | public-api | SupplierProposalController@uploadImage | None |
| GET `/public/invitations/{token}/proposal-image/{path}` | GET | public-api | SupplierProposalController@image | S3 static |
| GET `/public/currencies` | GET | public-api | CurrencyController@activePublicList | 1h |
| GET `/public/catalog-categories` | GET | public-api | CatalogCategoryController@publicList | 1h |
| GET `/public/catalog-products/search` | GET | public-api | CatalogProductController@publicSearch | 15m |

**Throttles:**
- `public-api`: ? (no especificado en config, asumir 60/min)

---

### 5.2 Autenticado (Sanctum)

**Estándar:**
- Header: `Authorization: Bearer {token}`
- Refresh automático vía middleware `refresh.token`
- Rate limit: `throttle:api` (180/min default)
- Algunos GET: `throttle:catalog` (200/min, para consultas de referencia)

#### 5.2.1 Auth & User Management

| Endpoint | Método | Auth | Rate | Responsibilidad |
|----------|--------|------|------|-----------------|
| GET `/user` | GET | Sanctum | api | AuthController@me (perfil actual) |
| GET `/auth/permissions` | GET | Sanctum | api | AuthController@permissions (matriz desde config/permissions.php) |
| POST `/logout` | POST | Sanctum | api | AuthController@logout |
| GET `/roles` | GET | ADMIN | api | UserController@roles |
| GET `/users` | GET | ADMIN | api | UserController@index |
| POST `/users` | POST | ADMIN | api | UserController@store |
| PATCH `/users/{user}` | PATCH | ADMIN | api | UserController@update |
| POST `/users/{user}/toggle-status` | POST | ADMIN | api | UserController@toggleStatus |
| POST `/users/{user}/send-reset-link` | POST | ADMIN | api | UserController@sendResetLink |

#### 5.2.2 Projects (Core)

| Endpoint | Método | Roles | Rate | Responsabilidad |
|----------|--------|-------|------|-----------------|
| GET `/projects` | GET | Sanctum | api | ProjectController@index (paginado 20-100 items) |
| POST `/projects` | POST | INFRAESTRUCTURA | api | ProjectController@store (crear obra) |
| GET `/projects/{id}` | GET | Sanctum | api | ProjectController@show |
| POST `/projects/{id}/review` | POST | CIERRE_DE_OBRA | api | ProjectController@review (auditar expediente) |
| POST `/projects/{id}/evaluate-dossier` | POST | CIERRE_DE_OBRA | api | ProjectController@evaluateDossier (IA async) |
| POST `/projects/{id}/reject-project` | POST | CIERRE_DE_OBRA | api | ProjectController@rejectProject |
| POST `/projects/{id}/resubmit` | POST | INFRAESTRUCTURA | api | ProjectController@resubmitProject |
| POST `/projects/{id}/approve-investment` | POST | PROCURA | api | ProjectController@approveInvestment |
| POST `/projects/{id}/proposals` | POST | ANALISTA | api | ProjectController@addProposal |
| DELETE `/projects/{id}/proposals/{proposalId}` | DELETE | ANALISTA | api | ProjectController@removeProposal |
| POST `/projects/{id}/proposals/{proposalId}/renegotiate` | POST | ANALISTA | api | ProjectController@renegotiateProposal |
| POST `/projects/{id}/submit-comparative` | POST | ANALISTA | api | ProjectController@submitComparative |
| POST `/projects/{id}/import-supplier-proposals` | POST | ANALISTA | api | ProjectController@importSupplierProposals |
| POST `/projects/{id}/reject-proposals` | POST | PROCURA | api | ProjectController@rejectProposals |
| POST `/projects/{id}/select-contractor` | POST | PROCURA | api | ProjectController@selectContractor |
| POST `/projects/{id}/payments` | POST | FINANZAS | api | ProjectController@pay |
| POST `/projects/{id}/report-finished` | POST | CIERRE_DE_OBRA | api | ProjectController@reportFinished |
| POST `/projects/{id}/verify-completion` | POST | CIERRE_DE_OBRA | api | ProjectController@verifyCompletion |

#### 5.2.3 Project Documents (Versionado)

| Endpoint | Método | Roles | Rate | Responsabilidad |
|----------|--------|-------|------|-----------------|
| GET `/projects/{id}/documents` | GET | Sanctum | catalog | ProjectDocumentController@index (versionado) |
| POST `/projects/{id}/documents` | POST | INFRAESTRUCTURA | api | ProjectDocumentController@upload (S3) |
| DELETE `/projects/{id}/documents/{docId}` | DELETE | INFRAESTRUCTURA | api | ProjectDocumentController@destroy (soft delete) |
| GET `/projects/{id}/documents/{docId}/download` | GET | Sanctum | catalog | ProjectDocumentController@download (S3 pre-signed URL) |
| GET `/projects/{id}/documents/{docId}/preview` | GET | Sanctum | catalog | ProjectDocumentController@preview (thumbnail) |
| GET `/projects/{id}/documents/{docId}/history` | GET | Sanctum | catalog | ProjectDocumentController@history (versions) |

#### 5.2.4 Supplier Proposals

| Endpoint | Método | Roles | Rate | Responsabilidad |
|----------|--------|-------|------|-----------------|
| POST `/supplier-invitations` | POST | ANALISTA | api | SupplierInvitationController@store (enviar invitación) |
| GET `/supplier-invitations/latest` | GET | Sanctum | catalog | SupplierInvitationController@latest |
| GET `/supplier-material-proposals` | GET | ANALISTA | api | SupplierProposalController@index |
| GET `/supplier-proposal-images/{token}/{path}` | GET | Sanctum | catalog | SupplierProposalController@internalImage |

#### 5.2.5 Notifications

| Endpoint | Método | Roles | Rate | Responsabilidad |
|----------|--------|-------|------|-----------------|
| GET `/notifications` | GET | Sanctum | api | AppNotificationController@index |
| GET `/notifications/unread-count` | GET | Sanctum | api | AppNotificationController@unreadCount |
| PATCH `/notifications/{id}/read` | PATCH | Sanctum | api | AppNotificationController@markRead |
| PATCH `/notifications/read-all` | PATCH | Sanctum | api | AppNotificationController@markAllRead |
| DELETE `/notifications/{id}` | DELETE | Sanctum | api | AppNotificationController@destroy |
| DELETE `/notifications` | DELETE | Sanctum | api | AppNotificationController@destroyAll |
| POST `/push-tokens` | POST | Sanctum | api | PushTokenController@store (Expo) |
| DELETE `/push-tokens` | DELETE | Sanctum | api | PushTokenController@destroy |

#### 5.2.6 Settings & Configuration

| Endpoint | Método | Roles | Rate | Responsabilidad |
|----------|--------|-------|------|-----------------|
| GET `/settings` | GET | Sanctum | catalog | AppSettingController@index (caché 24h) |
| GET `/settings/notification-actions` | GET | Sanctum | catalog | AppSettingController@notificationActions |
| PATCH `/settings/{id}` | PATCH | SUPERADMIN | api | AppSettingController@update (invalida caché) |
| GET `/config-audit-logs` | GET | SUPERADMIN | catalog | ConfigAuditLogController@index |
| GET `/notification-rules` | GET | SUPERADMIN | catalog | NotificationRuleController@index |
| PUT `/notification-rules` | PUT | SUPERADMIN | api | NotificationRuleController@update (matriz rol × acción) |

#### 5.2.7 Currencies & Exchange Rates

| Endpoint | Método | Roles | Rate | Responsabilidad |
|----------|--------|-------|------|-----------------|
| GET `/currencies/base` | GET | Sanctum | catalog | CurrencyController@base |
| GET `/currencies` | GET | SUPERADMIN | catalog | CurrencyController@index |
| POST `/currencies` | POST | SUPERADMIN | api | CurrencyController@store |
| PATCH `/currencies/{id}` | PATCH | SUPERADMIN | api | CurrencyController@update |
| POST `/currencies/{id}/set-base` | POST | SUPERADMIN | api | CurrencyController@setBase |
| DELETE `/currencies/{id}` | DELETE | SUPERADMIN | api | CurrencyController@destroy |
| GET `/exchange-rates` | GET | SUPERADMIN | catalog | ExchangeRateController@index |
| GET `/exchange-rates/{code}/history` | GET | SUPERADMIN | catalog | ExchangeRateController@history |
| POST `/exchange-rates` | POST | SUPERADMIN | api | ExchangeRateController@store |

#### 5.2.8 Catalog (Maestro de Productos)

| Endpoint | Método | Roles | Rate | Responsabilidad |
|----------|--------|-------|------|-----------------|
| GET `/catalog-categories` | GET | SUPERADMIN | catalog | CatalogCategoryController@index |
| POST `/catalog-categories` | POST | SUPERADMIN | api | CatalogCategoryController@store |
| PATCH `/catalog-categories/{id}` | PATCH | SUPERADMIN | api | CatalogCategoryController@update |
| DELETE `/catalog-categories/{id}` | DELETE | SUPERADMIN | api | CatalogCategoryController@destroy |
| GET `/catalog/products` | GET | PRESIDENCIA | catalog | CatalogProductController@index (submódulo) |
| GET `/catalog/products/{id}` | GET | PRESIDENCIA | catalog | CatalogProductController@show |
| GET `/catalog/products/{id}/price-history` | GET | PRESIDENCIA | catalog | CatalogProductController@priceHistory |
| GET `/custom-product-resolutions/pending` | GET | PRESIDENCIA | catalog | CustomProductResolutionController@pending |
| POST `/supplier-material-proposal-lines/{id}/resolve-product` | POST | PRESIDENCIA | api | CustomProductResolutionController@store |

#### 5.2.9 AI

| Endpoint | Método | Roles | Rate | Responsabilidad |
|----------|--------|-------|------|-----------------|
| GET `/ai/config` | GET | SUPERADMIN | catalog | AiConfigController@index (lista de configuraciones) |
| POST `/ai/config` | POST | SUPERADMIN | api | AiConfigController@store (crear proveedor IA) |
| PATCH `/ai/config/{id}` | PATCH | SUPERADMIN | api | AiConfigController@update |
| DELETE `/ai/config/{id}` | DELETE | SUPERADMIN | api | AiConfigController@destroy |
| POST `/ai/config/{id}/test` | POST | SUPERADMIN | api | AiConfigController@test (test connection) |
| POST `/ai/config/sync` | POST | SUPERADMIN | api | AiConfigController@sync (sincronizar modelos) |
| GET `/ai/config/models` | GET | SUPERADMIN | catalog | AiConfigController@availableModels |
| GET `/ai/config/usage` | GET | SUPERADMIN | catalog | AiConfigController@usage (consumo de tokens) |
| POST `/ai/evaluate-proposals` | POST | PROCURA | api | AIEvaluationController@evaluate (evaluación de propuestas) |

#### 5.2.10 Dashboard & Analytics

| Endpoint | Método | Roles | Rate | Responsabilidad |
|----------|--------|-------|------|-----------------|
| GET `/dashboard/summary` | GET | PRESIDENCIA | api | DashboardSummaryController@__invoke (KPIs sin paginación) |

#### 5.2.11 Catalogs de Referencia

| Endpoint | Método | Roles | Rate | Responsabilidad |
|----------|--------|-------|------|-----------------|
| GET `/modules` | GET | Sanctum | catalog | ModuleController@index (features disponibles) |
| GET `/contractors` | GET | Sanctum | catalog | ContractorController@activeList |
| POST `/contractors/{code}/rating` | POST | Sanctum | api | ContractorController@updateRating |
| GET `/contractors/config` | GET | ADMIN | api | ContractorController@index (config view) |
| POST `/contractors/config` | POST | ADMIN | api | ContractorController@store |
| GET `/contractors/config/{code}` | GET | ADMIN | api | ContractorController@show |
| PATCH `/contractors/config/{code}` | PATCH | ADMIN | api | ContractorController@update |
| POST `/contractors/config/{code}/toggle-status` | POST | ADMIN | api | ContractorController@toggleStatus |
| GET `/materials` | GET | Sanctum | catalog | MaterialController@activeList |
| GET `/materials/config` | GET | ADMIN | api | MaterialController@index |
| POST `/materials/config` | POST | ADMIN | api | MaterialController@store |
| GET `/materials/config/{id}` | GET | ADMIN | api | MaterialController@show |
| PATCH `/materials/config/{id}` | PATCH | ADMIN | api | MaterialController@update |
| POST `/materials/config/{id}/toggle-status` | POST | ADMIN | api | MaterialController@toggleStatus |

#### 5.2.12 Audit

| Endpoint | Método | Roles | Rate | Responsabilidad |
|----------|--------|-------|------|-----------------|
| GET `/audit-logs` | GET | Sanctum | catalog | AuditLogController@index (buffer mode, sin paginación) |

---

## 🚀 6. GUÍAS DE OPTIMIZACIÓN

### 6.1 Query Optimization Patterns

#### Pattern 1: Agregaciones SQL

**Bad:**
```php
$projects = Project::with(['proposals', 'payments'])->get();
foreach ($projects as $project) {
    $totalPayments = $project->payments->sum('amount');
}
```

**Good:**
```php
$projects = Project::query()
    ->withSum('payments', 'amount')
    ->withCount('proposals')
    ->get();
    
// Acceso: $project->payments_sum_amount, $project->proposals_count
```

---

#### Pattern 2: Lazy Eager Loading

**Bad:**
```php
$projects = Project::paginate(20);
foreach ($projects as $project) {
    // N+1 query por cada proyecto
    echo $project->materials->count();
}
```

**Good:**
```php
$projects = Project::paginate(20);
$projects->load('materials'); // Carga todas en 1 query después de la paginación
```

---

#### Pattern 3: Selective Columns

**Bad:**
```php
$projects = Project::with('materials')->get(); // Trae TODAS las columnas
```

**Good:**
```php
$projects = Project::select('id', 'title', 'status')
    ->with(['materials' => fn($q) => $q->select('id', 'project_id', 'name')])
    ->get();
```

---

### 6.2 Caching Strategies

#### Strategy 1: Query Result Caching

```php
class ProjectRepository {
    public function findActive() {
        return Cache::tags(['projects', 'active'])->remember(
            'projects_active',
            3600,
            fn() => Project::where('status', '!=', 'COMPLETADO_PAGADO')
                ->with(['materials', 'proposals'])
                ->get()
        );
    }
    
    public function invalidate() {
        Cache::tags(['projects'])->flush();
    }
}
```

**Invalidar:** Cuando Project se crea/actualiza

---

#### Strategy 2: Computed Property Caching

```php
class Project extends Model {
    public function getApprovedInvestmentAttribute() {
        return Cache::remember(
            "project:{$this->id}:approved_investment",
            86400,
            fn() => $this->calculateApprovedInvestment()
        );
    }
    
    protected static function booting() {
        static::updated(function ($model) {
            Cache::forget("project:{$model->id}:approved_investment");
        });
    }
}
```

---

#### Strategy 3: Job Queue para Operaciones Pesadas

```php
// En ProjectController::importSupplierProposals()
dispatch(new ImportSupplierProposalsJob($project))->onQueue('high');

// Job:
class ImportSupplierProposalsJob implements ShouldQueue {
    public function handle(SupplierProposalImportService $service) {
        $service->import($this->project); // Async, no bloquea
        AuditLog::record($this->project, 'SYSTEM', 'Importación completada');
        event(new SupplierProposalsImported($this->project));
    }
}
```

---

### 6.3 Database Index Strategy

**Índices faltantes (propuestos):**

```sql
-- Para DashboardSummaryService
CREATE INDEX idx_projects_status_created_date ON projects(status, created_date DESC);

-- Para búsquedas rápidas de propuestas por proyecto
CREATE INDEX idx_project_proposals_project_id_status ON project_proposals(project_id, status);

-- Para histórico de precios por producto + proveedor
CREATE INDEX idx_product_price_history_product_supplier ON product_price_history(
    catalog_product_id, supplier_code, quoted_at DESC
);

-- Para auditoría con filtros
CREATE INDEX idx_audit_logs_project_role_date ON audit_logs(
    project_id, role, created_at DESC
);

-- Para notificaciones sin leer
CREATE INDEX idx_app_notifications_user_read ON app_notifications(
    user_id, read_at
);
```

---

### 6.4 API Response Optimization

#### Pagination Limits

```php
// En cada endpoint de listado
$perPage = min((int) $request->get('per_page', 20), 100);
$query->paginate($perPage);
```

---

#### Field Selection (Sparse Fieldsets)

```php
// RFC 7231 Sparse Fieldsets: ?fields[projects]=id,title,status
if ($request->has('fields')) {
    $fields = $request->get('fields');
    $columns = explode(',', $fields['projects'] ?? '');
    $query->select($columns)->get();
}
```

---

#### Cursor Pagination para datos grandes

```php
// En lugar de offset para AuditLog (100K+ registros)
$logs = AuditLog::orderByDesc('created_at')
    ->cursorPaginate(50);
    
// Response headers: Link, X-Cursor-Total
```

---

### 6.5 Notification Optimization

#### Bulk Notification Dispatch

```php
// Bad: envía 1 email por usuario
User::role('ADMIN')->each(fn($user) => $user->notify(new ProjectRejected($project)));

// Good: batch de 100 usuarios, cola de fondo
User::role('ADMIN')
    ->lazy(100) // Chunked query
    ->each(fn($users) => 
        dispatch(new NotifyUsersJob($users, new ProjectRejected($project)))
    );
```

---

#### Webhook Debouncing

```php
// Si WebSocket no es confiable, usar webhook con retry
Bus::chain([
    new SendWebhookJob($project, 'project.updated'),
    new RefreshClientCacheJob($project),
])->dispatch();
```

---

## ✔️ 7. CHECKLIST DE CODE REVIEW

### 7.1 Pre-Commit Checks

- [ ] **Linting:** `composer lint` (PHP_CodeSniffer)
  ```bash
  ./vendor/bin/phpcs app/ --standard=PSR12
  ```

- [ ] **Type Analysis:** `phpstan` level 5+
  ```bash
  ./vendor/bin/phpstan analyse app/ --level=5
  ```

- [ ] **Testing:** `php artisan test`
  ```bash
  php artisan test --env=testing
  ```

- [ ] **Database Migrations:** Revisar orden cronológico
  ```bash
  php artisan migrate:status
  ```

---

### 7.2 Architectural Review

#### Controllers

- [ ] ¿Tiene más de 200 líneas? → Extraer Service
- [ ] ¿Más de 10 métodos públicos? → Dividir en 2 controllers
- [ ] ¿Hace queries directas en lugar de usar Service? → Refactor
- [ ] ¿Maneja auditoría manualmente? → Usar Observer o Middleware
- [ ] ¿Tiene if/else por tipo? → Usar Strategy Pattern
- [ ] ¿Usa DB::transaction() correctamente?
- [ ] ¿Valida input con FormRequest?
- [ ] ¿Retorna Resource o Collection en lugar de raw array?

#### Services

- [ ] ¿Tiene más de 300 líneas? → Dividir responsabilidades
- [ ] ¿Más de 5 inyecciones de dependencia? → Puede ser patrón Facade
- [ ] ¿Hace queries complejas? → Extraer Repository
- [ ] ¿Maneja múltiples tipos de entidades? → Dividir por dominio
- [ ] ¿Tiene métodos private > private methods public? → Posible classe anidada
- [ ] ¿Usa static methods? → Considerar factory
- [ ] ¿Captura excepciones sin re-lanzar? → Anti-pattern, loguear + fallar

#### Models

- [ ] ¿Tiene lógica de negocio en métodos? → Mover a Service
- [ ] ¿Más de 30 atributos fillable? → Normalizar BD
- [ ] ¿Mutadores/accesores? → Usar Casts en lugar de métodos
- [ ] ¿Scopes más de 20 líneas? → Extraer Repository
- [ ] ¿Relaciones con condiciones? → Documentar por qué
- [ ] ¿Casting de tipos correcto? → array, json, int, float, boolean
- [ ] ¿Soft delete cuando necesario?

#### Migrations

- [ ] ¿Índices en columnas de FK? ✅ Requerido
- [ ] ¿Índices en columnas de filtrado (status, created_at)?
- [ ] ¿Tipos de datos optimizados? (varchar vs text, decimal vs float)
- [ ] ¿Foreign keys con ON DELETE/UPDATE clauses?
- [ ] ¿CHECK constraints para enums?
- [ ] ¿Nullable solo si realmente lo necesita?
- [ ] ¿Sin hardcoding de valores (usar config)?

---

### 7.3 Performance Review

- [ ] ¿Usa eager loading en relaciones?
  ```php
  ✅ Project::with('materials', 'proposals')->get()
  ❌ Project::all() + loop → $project->materials
  ```

- [ ] ¿N+1 queries? Correr: `php artisan query:monitor`

- [ ] ¿Queries sin WHERE que cargan todo?
  ```php
  ❌ Project::get(); // 1M registros
  ✅ Project::latest()->paginate(20);
  ```

- [ ] ¿Cálculos en PHP que deberían ser SQL?
  ```php
  ❌ $sum = collect($projects)->sum('approved_investment');
  ✅ Project::sum('approved_investment');
  ```

- [ ] ¿Cachés con invalidación claras?

- [ ] ¿Índices en queries lenta (verificar EXPLAIN)?

---

### 7.4 Security Review

- [ ] ¿Valida input con FormRequest?
- [ ] ¿Usa middleware de autenticación?
- [ ] ¿Checkea roles con middleware o policy?
  ```php
  ✅ Route::post(...)->middleware('role:ADMIN');
  ✅ $this->authorize('update', $project);
  ❌ if ($user->role === 'ADMIN') { ... } en el controller
  ```

- [ ] ¿Escapa output en resources/views?
- [ ] ¿Usa parameterized queries (Eloquent)?
- [ ] ¿Valida URLs con SsrfSafeUrl en calls a externos?
- [ ] ¿Rate limiting apropiado?
- [ ] ¿Sin datos sensibles en logs?
- [ ] ¿Sin secrets en variables de entorno con default?

---

### 7.5 Testing Review

- [ ] ¿Hay tests para la feature (Feature test)?
- [ ] ¿Tests de casos exitosos y de error?
- [ ] ¿Mocks de dependencias externas (IA, S3)?
- [ ] ¿Database isolation (rollback o in-memory)?
- [ ] ¿Assertions claras (no truthy/falsy solo)?
- [ ] ¿Factory para crear datos de test?

**Ejemplo:**
```php
public function test_project_creation_with_valid_data() {
    $data = ProjectFactory::definition();
    
    $response = $this->actingAs($this->user)->post('/api/projects', $data);
    
    $response->assertCreated();
    $this->assertDatabaseHas('projects', ['title' => $data['title']]);
}
```

---

### 7.6 Code Style & Conventions

- [ ] **Naming:**
  - ✅ Controllers: `ProjectController` (singular entidad)
  - ✅ Services: `PriceEstimationService` (verb + noun)
  - ✅ Methods: `calculateAverageCost()` (camelCase)
  - ❌ `calc_avg_cost` (snake_case en PHP)
  - ❌ `get()` (vago, usar `getApprovedAmount()`)

- [ ] **Formatting:**
  - ✅ 120 caracteres máximo por línea
  - ✅ 2-4 espacios de indentación (4 preferente)
  - ✅ Llaves en K&R style (next line para clases)

- [ ] **Constants:**
  - ✅ `class ProjectStatus { const CREATED = 'CREADO'; }`
  - ❌ `define('PROJECT_CREATED', 'CREADO');` (global namespace)

- [ ] **Docblocks:**
  - ✅ Métodos public con @param, @return
  - ✅ Clases con /** descripción breve */
  - ❌ Docblocks obvios: `/** Get the id */ public function getId()`

---

### 7.7 Dependency Review

- [ ] ¿Nuevas dependencias en composer.json?
  - [ ] ¿Auditadas con `composer audit`?
  - [ ] ¿Versiones pinned o ^X.Y?
  - [ ] ¿No agrega vulnerabilidades conocidas?

- [ ] ¿Conflictos con paquetes existentes?

---

### 7.8 Documentation Review

- [ ] ¿README actualizado si cambio setup?
- [ ] ¿Comentarios en lógica compleja?
  - Ej: Por qué se invalida caché en cierto punto
  - Ej: Por qué se usa serializado JSON en lugar de normalized table
- [ ] ¿API docs (Swagger/OpenAPI) actualizados?

---

## 📋 Summary Table: Anti-Patterns vs Fixes

| Anti-Pattern | Ubicación | Severidad | Fix Propuesto | Esfuerzo |
|--------------|-----------|-----------|--------------|----------|
| ProjectController bloated | ProjectController (500 lines) | 🔴 Alto | Extraer Services (5) | Medium |
| DashboardSummaryService múltiples responsabilidades | DashboardSummaryService | 🟡 Medio | Separar en 3-4 clases | Medium |
| N+1 en DashboardSummary.getSummary() | DashboardSummaryService | 🔴 Alto | Usar SQL aggregation | Low |
| Sin índices en queries críticas | Migrations recientes | 🟡 Medio | Agregar 5-6 índices | Low |
| Caché sin invalidación | PriceEstimationService | 🟡 Medio | Event-driven invalidation | Low |
| CHECK constraints incompletos | supplier_material_proposal_lines | 🟡 Medio | Validación en Model | Low |
| FK faltante (selected_proposal_id) | projects table | 🟡 Medio | Nueva migración | Low |
| Auditoría dispersa en controllers | 40+ AuditLog::record() | 🟡 Medio | Observer o Middleware | Medium |
| Rate limiting por IP sin por-usuario | api.php routes | 🟡 Medio | Usar Spatie rateable | Low |
| Sin SSRF en algunas llamadas HTTP | AiConfigurationService | 🔴 Alto | Usar SsrfSafeUrl en todas | Low |
| Tests unitarios escasos (3 vs 47 feature) | tests/ | 🟡 Medio | Agregar unit tests para Services | High |

---

## 🎓 Conclusión & Next Steps

### Fortalezas ✅

1. **Estructura sólida:** MVC + Services está bien separado
2. **Pruebas:** 47 tests de integración (cobertura 70%)
3. **Migraciones limpias:** Versionado apropiado, sin rollbacks
4. **Seguridad base:** Auth, SSRF, rate limiting presente
5. **IA integrada:** Strategy pattern bien implementado

### Areas de Mejora 🔧

1. **Performance:** Agregar índices + SQL aggregations
2. **Mantenibilidad:** Dividir controllers/services grandes
3. **Testing:** Pasar de integration a unit tests (Services)
4. **Observability:** Request tracing, request IDs
5. **Caching:** Event-driven invalidation en lugar de TTL fijo

### Prioridades (P0-P2)

**P0 (Inmediato):**
- [ ] Indexación de queries lentas
- [ ] Refactor DashboardSummaryService (SQL aggregation)
- [ ] Validación en Models (check constraints)

**P1 (Sprint siguiente):**
- [ ] Extraer ProjectStateMachine de ProjectController
- [ ] Event-driven cache invalidation
- [ ] Rate limiting por usuario real

**P2 (Fase siguiente):**
- [ ] Unit tests para Services (genera +100 nuevos tests)
- [ ] Request tracing middleware
- [ ] Refactor SupplierProposalImportService con Pipeline

---

**Documento generado:** 2026-08-28  
**Autor:** Análisis Automatizado  
**Siguiente revisión:** 2026-09-30
