# CLAUDE.md — Directrices de Desarrollo Backend IVOO

> **Prioridad absoluta:** Seguridad → DRY → SOLID → Laravel → API → Calidad.
> Este archivo es la fuente de verdad para cualquier tarea de código backend en este repositorio.
> Para detalle completo, consultar los archivos en `docs-infraestructura/BACKEND/Rules/`.

---

## 1. Identidad del Proyecto

**IVOO Gestión Infraestructura** — Backend API REST para la gestión del ciclo de vida de obras de infraestructura y mantenimiento.

| Capa | Tecnología | Versión |
|------|------------|---------|
| Framework | Laravel | 12 |
| Runtime | PHP | 8.2+ |
| Base de Datos | MySQL | — |
| Auth | Laravel Sanctum | SPA (cookie httpOnly) |
| WebSocket | Pusher PHP Server | 7.3 |
| Testing | PHPUnit | 11 |
| HTTP Client | Guzzle | 7.2 |
| HTML Scraping | Symfony DomCrawler | — |

### Stack de seguridad

- **Auth:** Sanctum SPA con cookies httpOnly (NO tokens Bearer)
- **CSRF:** Cookie `XSRF-TOKEN` + header `X-XSRF-TOKEN`
- **Rate limiting:** 180 req/min API, 10 req/min público, 5 login/15min
- **Roles:** 10 roles definidos en `app/Support/Roles.php`

### Roles del sistema

```
SUPERADMIN → Acceso total + auditoría exclusiva
ADMIN → Gestión administrativa
PRESIDENCIA → Dashboard ejecutivo
INFRAESTRUCTURA → Creación y gestión de proyectos
CIERRE_DE_OBRA → Auditoría de expedientes
PROCURA → Licitación y adjudicación
ANALISTA → Evaluación de ofertas
FINANZAS → Control de pagos
CATALOGOS → Gestión de catálogos
MARKETING → Contenido público
```

### Arquitectura de capas

```
Routes → Controllers → Services → Models → Database
         ↓                ↓
    Form Requests    Observers/Events
    API Resources    Notifications
```

**Detalle completo:** `ARQUITECTURA_BACKEND.md`.

---

## 2. DRY Estricto (Prioridad #1)

> *"No duplicar código bajo ninguna circunstancia."*

### Antes de escribir cualquier service o lógica

1. **Buscar** si ya existe un service que haga lo mismo o algo similar en `app/Services/`.
2. **Extender** un service existente es preferible a crear uno nuevo.
3. Si lógica se repite en 2+ controllers → **extraer a un service** inmediatamente.

### Services centralizados (NO duplicar su lógica)

| Service | Responsabilidad | Usado por |
|---------|-----------------|-----------|
| `ProjectStateMachine` | Transiciones de estado, validación, eager-load | `ProjectController` |
| `PriceEstimationService` | Cálculo EST (promedio 6 meses) | `ProjectController`, `SupplierProposalController`, `CustomProductResolutionController` |
| `RejectionService` | Flujo de rechazo transaccional | `ProjectController` |
| `NotificationDispatcher` | Notificaciones multicanales | `AppNotificationController`, `ProjectController` |
| `CatalogSyncService` | Sincronización catálogo | `ProjectController`, `SupplierProposalController`, `MaterialController` |
| `ConversionService` | Conversión multimoneda | `ProjectController`, `SupplierProposalController` |

### Services con responsabilidad única

```php
// ✅ CORRECTO — Un service, una responsabilidad
class PriceEstimationService { }  // Solo cálculo de precios
class CatalogSyncService { }      // Solo sincronización catálogo
class RejectionService { }        // Solo flujo de rechazo

// ❌ INCORRECTO — God service
class ProjectService {
    public function create() { }
    public function update() { }
    public function approve() { }
    public function reject() { }
    public function calculatePrice() { }
    public function sendNotification() { }
    // 15+ métodos = ❌
}
```

**Regla:** Si un service tiene más de 7 métodos públicos, dividirlo.

---

## 3. Seguridad (Prioridad #2)

### Autenticación

```php
// ✅ SIEMPRE auth:sanctum para rutas protegidas
Route::middleware('auth:sanctum')->group(function () {
    Route::apiResource('projects', ProjectController::class);
});

// ❌ NUNCA rutas sin auth para datos sensibles
```

### Rate limiting

```php
// Login: 5 intentos / 15 min por email+IP
RateLimiter::attempt(
    $request->email . '|' . $request->ip(),
    5,
    fn() => Auth::attempt($request->only('email', 'password'))
);
```

### Uploads

```php
// ✅ SIEMPRE validar tipo y tamaño
$validated = $request->validate([
    'file' => 'required|file|max:10240|mimes:pdf,jpg,jpeg,png',
]);
```

### SQL Injection

```php
// ✅ SIEMPRE parameterized queries
$projects = Project::where('status', $request->status)->get();

// ❌ NUNCA concatenar en queries raw
DB::select("SELECT * FROM projects WHERE status = '{$request->status}'");
```

### Secrets

```php
// ✅ Usar config() helper
$key = config('services.openai.api_key');

// ❌ NUNCA hardcodear
$key = 'sk-proj-abc123';
```

### Auditoría

```php
// ✅ SIEMPRE logear acciones críticas
AuditLog::create([
    'project_id' => $project->id,
    'user_id' => auth()->id(),
    'role' => auth()->user()->role,
    'action' => 'project.approved_investment',
    'details' => ['amount' => $request->amount],
]);
```

**Detalle completo:** `docs-infraestructura/BACKEND/Rules/03-SECURITY-RULES.md`.

---

## 4. SOLID + Clean Code

### S — Single Responsibility

Cada service, controller y model tiene **una sola responsabilidad**.

| Ejemplo | Responsabilidad |
|---------|-----------------|
| `ProjectStateMachine` | Solo transiciones de estado |
| `PriceEstimationService` | Solo cálculo de precios |
| `NotificationDispatcher` | Solo envío de notificaciones |
| `ContractorHistoryService` | Solo histórico de proveedores |

**Regla práctica:** Controllers delegan a services. Services contienen lógica de negocio. Models contienen relaciones y scopes.

### O — Open/Closed

```php
// ✅ Strategy pattern — extensible sin modificar
interface EvaluationStrategyInterface
{
    public function evaluate(array $data): EvaluationResult;
}

class ProposalEvaluationStrategy implements EvaluationStrategyInterface { }
class DossierEvaluationStrategy implements EvaluationStrategyInterface { }

// ✅ Providers de IA — intercambiables
interface AIProviderInterface
{
    public function evaluate(string $prompt): AIResponse;
}

class OpenAIProvider implements AIProviderInterface { }
class AnthropicProvider implements AIProviderInterface { }
class GeminiProvider implements AIProviderInterface { }
```

### L — Liskov Substitution

Todos los providers de IA implementan `AIProviderInterface` y son intercambiables sin romper el contrato.

### I — Interface Segregation

Interfaces pequeñas: `CanEvaluate`, `CanTrackUsage`, `CanValidateConnection`. Un provider puede implementar varias.

### D — Dependency Inversion

```php
// ✅ Constructor injection
class ProjectController extends Controller
{
    public function __construct(
        private ProjectStateMachine $stateMachine,
        private RejectionService $rejectionService,
        private NotificationDispatcher $notifier
    ) {}
}

// ❌ New directo
$service = new RejectionService();
```

### Clean Code

- Functions < 50 líneas
- Archivos < 300 líneas
- Nombres descriptivos (no abreviaturas)
- Sin código muerto ni imports sin usar
- Comentarios solo para "por qué", no para "qué"

**Detalle completo:** `docs-infraestructura/BACKEND/Rules/02-SOLID-RULES.md`.

---

## 5. Laravel — Convenciones del Framework

### Controllers

```php
// ✅ Singular + "Controller" suffix
class ProjectController extends Controller { }

// ✅ Form Requests para validación
public function store(StoreProjectRequest $request)
{
    $project = Project::create($request->validated());
    return new ProjectResource($project);
}

// ❌ No validación inline
public function store(Request $request)
{
    $validated = $request->validate([...]);
}
```

### Respuestas

```php
// ✅ API Resources siempre
return new ProjectResource($project);
return ProjectResource::collection($projects->paginate(15));

// ✅ Status codes correctos
return response()->json(['data' => $resource], 201);  // Created
return response()->noContent();  // Delete exitoso
```

### Eloquent

```php
// ✅ Eager loading siempre
$projects = Project::with(['materials', 'proposals', 'payments'])->get();

// ❌ N+1 queries
foreach (Project::all() as $project) {
    $project->materials; // ❌ Lazy loading
}

// ✅ Scopes
public function scopeActive(Builder $query): Builder
{
    return $query->where('status', 'CREADO');
}
```

### Migrations

```php
// ✅ Timestamps en nombres
2024_01_15_100000_create_projects_table.php

// ✅ Foreign keys siempre
$table->foreignId('user_id')->constrained()->cascadeOnDelete();

// ✅ UUID para projects, auto-increment para tablas internas
$table->uuid('id')->primary();
```

### Caching

```php
// ✅ Cache con TTL
Cache::remember("project:{$id}:materials", 300, fn() => $project->materials()->get());

// ✅ Invalidar al actualizar
Cache::forget("project:{$id}:materials");
```

**Detalle completo:** `docs-infraestructura/BACKEND/Rules/01-LARAVEL-RULES.md`.

---

## 6. API REST

### URLs

```
✅ CORRECTO:
GET    /api/projects
POST   /api/projects
GET    /api/projects/{id}
PATCH  /api/projects/{id}
POST   /api/projects/{id}/approve
POST   /api/projects/{id}/pay
```

### Status codes

| Código | Uso |
|--------|-----|
| `200` | OK (GET, PATCH) |
| `201` | Created (POST exitoso) |
| `204` | No Content (DELETE) |
| `401` | Unauthenticated |
| `403` | Forbidden (sin permiso) |
| `404` | Not Found |
| `422` | Validation Error |
| `429` | Too Many Requests |

### Rate limiting

| Endpoint | Límite | Ventana |
|----------|--------|---------|
| API general | 180 req/min | por usuario |
| Público | 10 req/min | por IP |
| Login | 5 intentos | 15 min por email+IP |
| Catálogos | 200 req/min | por usuario |

### Filtering

```php
// ✅ Query params para filtros
$projects = Project::query()
    ->when($request->status, fn($q, $s) => $q->where('status', $s))
    ->when($request->type, fn($q, $t) => $q->where('type', $t))
    ->paginate(15);
```

**Detalle completo:** `docs-infraestructura/BACKEND/Rules/05-API-DESIGN-RULES.md` y `docs-infraestructura/BACKEND/API-REFERENCE.md`.

---

## 7. Base de Datos

### Reglas críticas

1. **Eager loading siempre** — Evitar N+1 queries
2. **Paginación** — Nunca `->all()` en listas grandes
3. **Transacciones** — Para operaciones atómicas (múltiples writes)
4. **Foreign keys** — Siempre en migrations
5. **Índices** — En columnas de filtro frecuente
6. **Cache con TTL** — Para datos que no cambian mucho

### Transacciones

```php
// ✅ Operaciones atómicas
DB::transaction(function () use ($project, $data) {
    $project->update($data);
    $project->materials()->createMany($data['materials']);
    AuditLog::create([...]);
});

// ❌ Múltiples writes sin transacción
$project->update($data);
$project->materials()->createMany($data['materials']); // ❌ Si falla, inconsistente
```

### Soft deletes

```php
// ✅ Usar para datos restaurables
class ProjectDocument extends Model
{
    use SoftDeletes;
}

// ✅ Incluir eliminados cuando se necesite
$documents = ProjectDocument::withTrashed()->get();
```

**Detalle completo:** `docs-infraestructura/BACKEND/Rules/04-DATABASE-RULES.md`.

---

## 8. Errores Conocidos (NO repetir)

| # | Error | Solución |
|---|-------|----------|
| 1 | N+1 queries | Siempre eager loading con `with()` |
| 2 | God service | Dividir si >7 métodos públicos |
| 3 | Lógica en controllers | Delegar a services |
| 4 | Validación inline | Usar Form Requests |
| 5 | Respuestas manuales | Usar API Resources |
| 6 | Secrets hardcodeados | Usar `config()` helper |
| 7 | Migraciones sin FK | Siempre `constrained()` |
| 8 | Cache sin invalidación | `Cache::forget()` al actualizar |

**Detalle completo:** `docs-infraestructura/BACKEND/Rules/11-ERRORS-TO-AVOID.md`.

---

## 9. Protocolo MCP — Herramientas de Desarrollo

### GRAPHIFY (Grafo de código)

**Cuándo usar:** Antes de refactorizar, modificar estructura de carpetas, o cuando no estés seguro de qué archivos dependen de un service o controller.

**Cómo usar:**
1. Leer `graphify-out/COMPASS.md` → God Nodes del sistema.
2. Leer `graphify-out/GRAPH_REPORT.md` → Comunidades y dependencias.
3. Evaluar impacto antes de modificar cualquier service con alto acoplamiento.

**God Nodes del sistema** (mayormente internos de Laravel — los services de negocio son los puntos críticos reales):

| Node | Conexiones | Tipo |
|------|------------|------|
| `Str` | 595 edges | Laravel framework |
| `get()` | 574 edges | Laravel framework |
| `Configuration` | 484 edges | Laravel framework |
| `Builder` | 414 edges | Laravel Eloquent |
| `user()` | 258 edges | Auth |

**Services de negocio con mayor acoplamiento** (modificar con cuidado):

| Service | Función |
|---------|---------|
| `ProjectStateMachine` | Transiciones de estado — usado por múltiples controllers |
| `PriceEstimationService` | Cálculo de precios — usado por 3 controllers |
| `NotificationDispatcher` | Notificaciones — usado por controllers de proyecto |
| `CatalogSyncService` | Sincronización catálogo — usado por 4 controllers |

### OBSIDIAN (Documentación del vault)

**Cuándo usar:** Antes de cambios grandes en arquitectura, o cuando necesites contexto histórico del proyecto.

**Cómo usar:** El MCP de Obsidian ya tiene las rutas del vault cargadas. Consultar bitácoras de seguimiento y reportes de desarrollo cuando necesites entender el "por qué" de una decisión.

### SEQUENTIAL-THINKING (Razonamiento)

**Cuándo usar:** Bugs complejos que involucran múltiples services, refactorizaciones arquitectónicas, o decisiones con múltiples alternativas.

**Cómo usar:** Iniciar con `sequential-thinking` para descomponer el problema en pasos antes de escribir código.

---

## 10. Referencias y Checklist

### Referencias rápidas

| Necesitas... | Consulta... |
|--------------|-------------|
| Reglas Laravel | `docs-infraestructura/BACKEND/Rules/01-LARAVEL-RULES.md` |
| Reglas SOLID | `docs-infraestructura/BACKEND/Rules/02-SOLID-RULES.md` |
| Seguridad | `docs-infraestructura/BACKEND/Rules/03-SECURITY-RULES.md` |
| Base de datos | `docs-infraestructura/BACKEND/Rules/04-DATABASE-RULES.md` |
| API REST | `docs-infraestructura/BACKEND/Rules/05-API-DESIGN-RULES.md` |
| Commits | `docs-infraestructura/BACKEND/Rules/06-COMMIT-RULES.md` |
| Testing | `docs-infraestructura/BACKEND/Rules/07-TESTING-RULES.md` |
| ADRs | `docs-infraestructura/BACKEND/Rules/08-ADR.md` |
| Métricas | `docs-infraestructura/BACKEND/Rules/09-METRICS.md` |
| Checklists | `docs-infraestructura/BACKEND/Rules/10-CHECKLISTS.md` |
| Errores | `docs-infraestructura/BACKEND/Rules/11-ERRORS-TO-AVOID.md` |
| Índice reglas | `docs-infraestructura/BACKEND/Rules/00-INDEX.md` |
| Arquitectura | `ARQUITECTURA_BACKEND.md` |
| Schema BD | `docs-infraestructura/BACKEND/DATABASE-SCHEMA.md` |
| API completa | `docs-infraestructura/BACKEND/API-REFERENCE.md` |
| Models | `docs-infraestructura/BACKEND/Models/INDEX.md` |
| Controllers | `docs-infraestructura/BACKEND/Controllers/INDEX.md` |
| Services | `docs-infraestructura/BACKEND/Services/INDEX.md` |
| Grafo de código | `graphify-out/COMPASS.md` |

### Checklist Pre-Entrega

Antes de dar por terminada cualquier tarea, responder **SÍ** a todo:

1. ¿El service tiene responsabilidad única (<7 métodos públicos)?
2. ¿Los controllers delegan lógica a services?
3. ¿Usé Form Requests para validación?
4. ¿Usé API Resources para respuestas?
5. ¿Hay eager loading en queries con relaciones?
6. ¿Las operaciones atómicas usan transacciones?
7. ¿No hay secrets hardcodeados?
8. ¿La migración es reversible con foreign keys?
9. ¿`php artisan test` pasa limpio?
10. ¿El commit sigue Conventional Commits?
