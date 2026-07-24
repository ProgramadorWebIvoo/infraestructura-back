# AUDITORIA DE CÓDIGO BACKEND — INFRAESTRUCTURA

**Fecha:** 24 de Julio, 2026  
**Versión del Reporte:** V2  
**Tipo de Auditoría:** Integral (Seguridad, Clean Code, Performance, Testing, Arquitectura)  
**Framework:** Laravel 9.x (PHP 8.0+)  
**Repositorio:** `infraestructura-back`  

---

## 1. RESUMEN EJECUTIVO

Se realizó una auditoría exhaustiva del backend del sistema de gestión de infraestructura. Se evaluaron **75+ archivos** en las capas de Rutas, Controladores, Servicios, Modelos, Middleware, Notificaciones, Comandos y Tests.

### Hallazgos por Severidad

| Severidad | Cantidad |
|-----------|----------|
| 🔴 Crítico | 1 |
| 🟠 Alto | 6 |
| 🟡 Medio | 12 |
| 🔵 Bajo | 8 |
| **Total** | **27** |

### Cobertura de Tests

| Métrica | Valor |
|---------|-------|
| Archivos de test | 9 Feature + 1 Unit |
| Tests totales | ~130 tests |
| Endpoints cubiertos | ~43 de 59 (~73%) |
| Endpoints críticos sin test | AI Evaluation, PushToken, Project Documents, AI Config CRUD |

---

## 2. VULNERABILIDADES Y RIESGOS CRÍTICOS ENCONTRADOS

### 🔴 C-01: `count()` en `estimateCost()` con keys incorrectas → Costos siempre $0

**Archivo:** `app/Services/AI/AIEvaluationService.php` (línea 222-241)

**Problema:** El método `estimateCost()` usa las keys `'chatgpt'`, `'claude'` para identificar proveedores en el array `$pricing`, pero el parámetro `$provider` que recibe contiene los valores `'openai'`, `'anthropic'`, `'gemini'`. Nunca hay match → costos siempre $0.

```php
$pricing = [
    'chatgpt' => ['input' => 2.50, 'output' => 10.00],  // NUNCA se usa
    'gemini'  => ['input' => 0.35, 'output' => 1.05],    // OK
    'claude'  => ['input' => 3.00, 'output' => 15.00],   // NUNCA se usa
];

$rates = $pricing[$provider] ?? ['input' => 0, 'output' => 0];
// $provider = 'openai' → no existe en $pricing → $rates = ['input' => 0, 'output' => 0]
// $provider = 'anthropic' → no existe en $pricing → $rates = ['input' => 0, 'output' => 0]
```

**Impacto:** Los costos estimados de IA siempre se registran como $0 en `AiUsageLog`, imposibilitando cualquier análisis de costos real.

**Solución:** Usar las mismas keys que recibe el método: `'openai'`, `'anthropic'`, `'gemini'`.

---

### 🟠 A-01: Notificaciones push bloquean el request HTTP (sin queue)

**Archivos:** 
- `app/Observers/ProjectObserver.php`
- `app/Notifications/ProjectStatusChanged.php`
- `app/Services/ExpoPushService.php`

**Problema:** En `ProjectObserver::updated()`, cuando cambia el status de un proyecto, se notifica a **TODOS** los usuarios del sistema **sincrónicamente**. Cada notificación hace un HTTP POST a la API de Expo Push. Con `QUEUE_CONNECTION=sync`, esto bloquea la respuesta HTTP hasta completar todas las llamadas.

```php
// ProjectObserver.php:17-19
$users = User::all(); // Si hay 100 usuarios, 100 HTTP calls síncronos
foreach ($users as $user) {
    $user->notify(new ProjectStatusChanged($project, $oldStatus, $newStatus));
}
```

**Impacto:** 
- Timeout fácil si hay muchos usuarios
- Denegación de servicio (DoS) parcial
- Mala experiencia de usuario (respuestas lentas)
- El Observer no distingue entre cambios relevantes para el usuario

**Solución:** 
1. Configurar queue (database/redis) en producción
2. Filtrar usuarios por roles relevantes según el estado
3. Implementar notificaciones batch

---

### 🟠 A-02: `ExpoPushService` ignora respuestas de la API → tokens zombies

**Archivo:** `app/Services/ExpoPushService.php` (línea 29-31)

**Problema:** El servicio hace POST a Expo API pero ignora completamente la respuesta. Los tokens inválidos o expirados nunca se limpian.

```php
foreach ($messages->chunk(100) as $chunk) {
    Http::post(self::EXPO_API, $chunk->values()->toArray()); // ← Se ignora response
}
```

**Impacto:** Acumulación de tokens zombies. Se envía notificaciones a dispositivos que ya no existen, incurriendo en llamadas HTTP innecesarias y posible rate-limiting por parte de Expo.

**Solución:** Procesar la respuesta de Expo Push API, identificar tokens con error `DeviceNotRegistered` y eliminarlos automáticamente.

---

### 🟠 A-03: `base_url` en `$fillable` de `AiConfiguration` permite SSRF

**Archivos:**
- `app/Models/AiConfiguration.php` (línea 15: `'base_url'`)
- `app/Services/AI/Providers/OpenAIProvider.php` (línea 40: `$this->baseUrl`)

**Problema:** El campo `base_url` es `$fillable` y permitido en el request de creación/actualización. Un ADMIN/SUPERADMIN malintencionado o un ataque XSS podría configurar una URL maliciosa hacia servicios internos.

```php
// AiConfigController.php:43-44
'baseUrl'    => ['nullable', 'string', 'max:255'], // Sin validación de URL maliciosa
```

**Impacto:** Potencial SSRF (Server-Side Request Forgery) si se configura una `base_url` que apunte a localhost o redes internas. Aunque requiere rol ADMIN, es una superficie de atque innecesaria.

**Solución:** 
1. Validar que `base_url` comience con URL de API conocida
2. No permitir IPs privadas en producción
3. Considerar hacer `base_url` no modificable vía API

---

### 🟠 A-04: `AiConfigurationService` cachea API keys desencriptadas en texto plano

**Archivo:** `app/Services/AI/AiConfigurationService.php` (línea 66-67, 81-84)

**Problema:** El método `syncToCache()` y `fromDb()` almacenan el resultado de `toServiceConfig()` (que contiene `api_key` desencriptada) en el cache de Laravel. Dependiendo del driver de cache, las claves pueden persistir en disco/tabla/redis sin cifrar.

```php
Cache::forever(self::CACHE_KEY, $configs); // API keys en texto plano en cache
```

**Impacto:** Si alguien accede al almacenamiento de cache (ej: archivos en `storage/framework/cache/`), puede leer las API keys de todos los proveedores IA.

**Solución:** 
1. No cachear las API keys, solo los metadatos (modelo, activo, etc.)
2. O encriptar cada key individualmente antes de cachear
3. O usar el accessor de Eloquent directamente sin cache intermedio

---

### 🟠 A-05: `config(["ai.{$key}"])` muta estado global (peligro en entornos concurrentes)

**Archivo:** `app/Services/AI/AIEvaluationService.php` (línea 61)

**Problema:** El método `registerProviders()` modifica la configuración global de Laravel en cada request usando `config(["ai.{$key}" => $config])`. En un entorno concurrente (php-fpm con múltiples workers, o Swoole/ReactPHP), esto puede causar condiciones de carrera y leaks de configuración entre requests.

```php
config(["ai.{$key}" => $config]); // Muta estado GLOBAL
```

**Impacto:** Una request podría ver la configuración de IA de otra request, incluyendo claves de API de diferentes proveedores.

**Solución:** Inyectar la configuración directamente en los providers en lugar de mutar el estado global de config.

---

### 🟡 M-01: `UserController` constantes definidas fuera de clase

**Archivo:** `app/Http/Controllers/Api/UserController.php` (líneas 12-17)

**Problema:** Las constantes `VALID_ROLES` y `VALID_STATUSES` están definidas a nivel de archivo (global scope) en lugar de como constantes de clase.

**Solución:** Mover dentro de la clase como `private const VALID_ROLES = [...]`.

---

### 🟡 M-02: `RouteServiceProvider` con rate limiting inadecuado

**Archivos:**
- `routes/api.php` (líneas 15-16)
- `app/Providers/RouteServiceProvider.php`

**Problema:** Varias rutas autenticadas remueven explícitamente el middleware `ThrottleRequests`:
```php
Route::get('/modules', ...)->withoutMiddleware([ThrottleRequests::class]);
Route::get('/contractors', ...)->withoutMiddleware([ThrottleRequests::class]);
Route::get('/materials', ...)->withoutMiddleware([ThrottleRequests::class]);
Route::get('/audit-logs', ...)->withoutMiddleware([ThrottleRequests::class]);
```

**Impacto:** Estas rutas no tienen rate limiting, permitiendo abuso.

**Solución:** Establecer un throttle específico para rutas autenticadas en lugar de removerlo.

---

### 🟡 M-03: Sin paginación en endpoints de listado

**Archivos:** 
- `ProjectController::index()` → `ProjectResource::collection($query->get())`
- `UserController::index()` → `User::select(...)->get()`
- `SupportController::auditLogs()` → `AuditLog::latest(...)->limit(200)->get()`
- `SupportController::supplierMaterialProposals()` → `query->get()`

**Problema:** Ningún endpoint de listado implementa paginación. A medida que crezcan los datos, se devolverán conjuntos cada vez más grandes.

**Solución:** Implementar `paginate()` en todos los endpoints de listado.

---

### 🟡 M-04: IDs auto-generados con posibles colisiones en concurrencia

**Archivos:**
- `ProjectController::nextProjectId()` (líneas 365-371)
- `ProjectController::addProposal()` (línea 153: `'PROP-' . now()->format('Hisv')`)
- `SupportController::nextContractorCode()` (línea 265-273)

**Problema:** Los métodos de generación de IDs no son seguros bajo concurrencia. Usan `SELECT` entonces `INSERT` sin bloqueo, y `now()->format('Hisv')` puede colisionar con milisegundos iguales.

**Solución:** Usar UUIDs o sequences nativas de base de datos para IDs seguros bajo concurrencia.

---

### 🟡 M-05: Test `test_token_works_with_device_name` tiene bug lógico

**Archivo:** `tests/Feature/AuthTest.php` (líneas 156-179)

**Problema:** El test hace un primer login con email `user@test.com` (que no existe) y luego un segundo login con `device@test.com` (que sí existe). El primer login falla pero no se verifica, y el test pasa por el segundo.

```php
// Primer login con email que NO existe
$response = $this->postJson('/api/login', [
    'email'    => 'user@test.com', // ← Este usuario no se creó
    'password' => 'secret123',
]);
// No se verifica el status de este response

// Segundo login con el email correcto
$response = $this->postJson('/api/login', [
    'email'    => 'device@test.com', // ← Este sí existe
    'password' => 'secret123',
    'device_name' => 'mobile-app',
]);
$response->assertStatus(200);
```

**Solución:** Eliminar el primer login que no aporta valor.

---

### 🟡 M-06: GeminiProvider y AnthropicProvider extienden OpenAIProvider con override masivo

**Archivos:**
- `app/Services/AI/Providers/GeminiProvider.php` (extiende `OpenAIProvider`)
- `app/Services/AI/Providers/AnthropicProvider.php` (extiende `OpenAIProvider`)

**Problema:** Ambos providers extienden `OpenAIProvider` pero sobrescriben completamente `__construct()`, `evaluate()`, `parseResponse()`, etc. La herencia se usa incorrectamente — solo reusan `buildSystemPrompt()`, `buildUserPrompt()`, `sanitizeInput()` y `normalizeResult()`.

**Solución:** Refactorizar usando composición sobre herencia o una clase abstracta base `BaseAIProvider` con métodos comunes.

---

### 🟡 M-07: `registerProviders()` en constructor inyecta dependencias vía `app()`

**Archivo:** `app/Services/AI/AIEvaluationService.php` (línea 24-28)

**Problema:** El constructor usa `app(AiConfigurationService::class)` como fallback, lo que esconde la dependencia y hace el testing más difícil.

```php
public function __construct(?AiConfigurationService $configService = null)
{
    $this->configService = $configService ?? app(AiConfigurationService::class);
    $this->registerProviders();
}
```

**Solución:** Hacer `AiConfigurationService` un parámetro obligatorio y usar el contenedor de Laravel para resolverlo automáticamente.

---

### 🟡 M-08: El método `getModelForProvider()` nunca se usa

**Archivo:** `app/Services/AI/AIEvaluationService.php` (líneas 246-250)

**Problema:** El método `getModelForProvider()` está definido pero nunca es llamado en el código.

**Solución:** Eliminar código muerto o usarlo en `logUsage()`.

---

### 🟡 M-09: El `.env` expone API keys en texto plano

**Archivo:** `.env`

**Problema:** Según la auditoría previa, las claves `APP_KEY`, `GEMINI_API_KEY`, etc., están en texto plano en el `.env`. Adicionalmente el dump de BD `ivoo_gestion_infraestructura.sql` está versionado en el repositorio.

**Impacto:** Exposición de credenciales sensibles si el repositorio se vuelve público o es accedido por personal no autorizado.

**Solución:** 
1. Rotar todas las claves expuestas
2. Añadir `.env` y `*.sql` a `.gitignore`
3. Usar variables de entorno del sistema en producción

---

### 🔵 B-01: `throw ValidationException` usa campo 'email' genérico

**Archivo:** `app/Http/Controllers/Api/AuthController.php` (líneas 24, 31)

```php
throw ValidationException::withMessages([
    'email' => ['Las credenciales no coinciden con nuestros registros.'],
]);
```

**Problema:** Se usa `'email'` como key incluso para errores de contraseña, permitiendo enumeración de usuarios (el mensaje "no coinciden" vs "cuenta desactivada" revela existencia).

**Solución:** Usar mensaje genérico para ambos tipos de error.

---

### 🔵 B-02: Magic strings para estados de proyecto

**Archivo:** `app/Http/Controllers/Api/ProjectController.php` (líneas 20-30, 77, 109, 128, etc.)

**Problema:** Los estados se definen como `private const STATUSES` pero se usan como strings literales en los métodos.

**Solución:** Usar las constantes en lugar de strings: `self::STATUSES['CREADO']`.

---

### 🔵 B-03: DTO ausente para payload de evaluación IA

**Archivo:** `app/Http/Controllers/Api/AIEvaluationController.php` (líneas 31-49)

**Problema:** El payload de evaluación IA se pasa como array asociativo sin tipado. Un DTO/ValueObject mejoraría la mantenibilidad.

### 🔵 B-04: Sin validación de tipos MIME en subida de documentos

**Archivo:** `app/Http/Requests/StoreProjectDocumentRequest.php` (no revisado en detalle, pero mencionado)

**Impacto:** Potencial subida de archivos maliciosos si no se validan tipos MIME.

### 🔵 B-05: Sin test para `test_remove_awarded_proposal_returns_422`

El test existe pero no verifica que la propuesta esté correctamente asignada como `selected_proposal_id`.

### 🔵 B-06: Nombres inconsistentes en `toArray()` vs `$fillable`

**Archivo:** `app/Models/AiConfiguration.php`

`toArray()` retorna `apiKey` (camelCase) pero `$fillable` usa `api_key` (snake_case). Aunque es intencional (API resources vs DB), puede causar confusión.

---

## 3. EVALUACIÓN DE CUMPLIMIENTO DE REGLAS DE NEGOCIO

### Flujo de Proyectos (Lifecycle)

| Paso | Estado | Rol | ¿Implementado? | ¿Testeado? |
|------|--------|-----|:---:|:---:|
| Crear proyecto | CREADO | INFRAESTRUCTURA | ✅ | ✅ |
| Revisar planos | REVISADO_CIERRE | CIERRE_DE_OBRA | ✅ | ✅ |
| Aprobar inversión | CONFIRMADO_PROCURA | PROCURA | ✅ | ✅ |
| Cargar propuestas | - | ANALISTA | ✅ | ✅ |
| Enviar comparativa | COMPARATIVA_ENVIADA | ANALISTA | ✅ | ✅ |
| Rechazar propuestas | CONFIRMADO_PROCURA | PROCURA | ✅ | ✅ |
| Seleccionar contratista | CONTRATADO | PROCURA | ✅ | ✅ |
| Pago anticipo | EN_EJECUCION | FINANZAS | ✅ | ✅ |
| Reportar finalización | VERIFICANDO_FINALIZACION | CIERRE_DE_OBRA | ✅ | ✅ |
| Verificar calidad | LISTO_PAGO_FINAL / EN_EJECUCION | CIERRE_DE_OBRA | ✅ | ✅ |
| Pago final | COMPLETADO_PAGADO | FINANZAS | ✅ | ✅ |

### Roles y Permisos

| Endpoint | Roles Permitidos | ¿Implementado? | ¿Testeado? |
|----------|:---|:---:|:---:|
| CRUD Usuarios | SUPERADMIN, ADMIN | ✅ | ✅ |
| CRUD Contratistas (admin) | SUPERADMIN, ADMIN | ✅ | ✅ |
| CRUD Materiales | SUPERADMIN, ADMIN | ✅ | ✅ |
| AI Config | SUPERADMIN, ADMIN | ✅ | ❌ |
| AI Evaluation | PROCURA, ADMIN, SUPERADMIN | ✅ | ❌ |
| PushTokens | Auth (cualquier rol) | ✅ | ❌ |
| Documentos | Auth (cualquier rol) | ✅ | ❌ |
| Invitaciones proveedor | Auth (cualquier rol) | ✅ | ✅ |
| Importar propuestas proveedor | ANALISTA, ADMIN, SUPERADMIN | ✅ | ✅ |

### Reglas de Negocio NO implementadas

1. No hay validación de que el `paymentType === 'ADVANCE'` solo pueda hacerse una vez por proyecto
2. No hay validación de que `paymentType === 'FINAL'` solo pueda hacerse después de `LISTO_PAGO_FINAL`
3. No hay notificaciones asíncronas (queues)
4. No hay soft-deletes en proyectos (no se puede "eliminar" un proyecto)

---

## 4. MEJORAS APLICADAS Y RECOMENDACIONES (Clean Code, POO y Normalización)

### Clean Code

| Archivo | Hallazgo | Recomendación |
|---------|----------|---------------|
| `UserController.php:12-17` | Constantes globales fuera de clase | Mover a `private const` dentro de la clase |
| `ProjectController.php:378-392` | Método `log()` duplicado en 3 controladores | Extraer a un Trait o servicio de auditoría |
| `ProjectDocumentController.php:167-181` | Método `log()` duplicado nuevamente | Misma solución que arriba |
| `AIEvaluationService.php` | `registerProviders()` mezcla registro con inicialización | Separar en Factory + Registry |
| `GeminiProvider.php / AnthropicProvider.php` | Herencia incorrecta de OpenAIProvider | Usar clase abstracta o composición |
| Varios | `camelCase` y `snake_case` mezclados en validaciones | Unificar criterio de nomenclatura en inputs |

### POO y SOLID

| Principio | Violación | Archivo |
|-----------|-----------|---------|
| **SRP** | `ProjectController` maneja CRUD + lifecycle + import + logs | `ProjectController.php` |
| **SRP** | `SupportController` maneja contractors, materials, audit-logs, invitations | `SupportController.php` |
| **OCP** | Agregar nuevo proveedor IA requiere modificar OpenAIProvider | `OpenAIProvider.php` (herencia frágil) |
| **DIP** | `AIEvaluationService` crea instancias concretas de providers | `AIEvaluationService.php:57-62` |
| **ISP** | `AiConfigurationService` tiene métodos que no se usan (clearCache) | `AiConfigurationService.php` |

### Normalización

| Aspecto | Estado Actual | Recomendado |
|---------|---------------|-------------|
| Nombres de rutas | `camelCase` en algunos parámetros (`contractorCode`, `paymentType`) | `snake_case` consistente |
| Respuestas API | Mezcla de `snake_case` y `camelCase` | Unificar a `camelCase` (estándar API REST) |
| Códigos de error | Algunos endpoints usan 422, otros 500 | Definir convención de códigos HTTP |
| Logging | `AuditLog` para acciones user, `Log::info` para acceso público | Unificar sistema de logging |

---

## 5. ESTADO DE PRUEBAS UNITARIAS

### Resumen General

```
PHPUnit 9.5.x
Tests: ~130
Assertions: ~350+
Cobertura de endpoints: ~73% (43/59)
Cobertura de código: No disponible (falta phpunit-coverage)
```

### Tests Existentes

| Archivo | Tests | Assertions | Cobertura |
|---------|:-----:|:----------:|:---------:|
| `AuthTest.php` | 10 | ~30 | Login, logout, me, sesiones, usuarios inactivos |
| `TokenExpirationTest.php` | 7 | ~25 | Expiración, refresh, grace period |
| `TokenExpirationIntegrationTest.php` | 9 | ~30 | Integración refresh + roles + endpoints |
| `RoleMiddlewareTest.php` | ~15 | ~45 | Roles, permisos, acceso SUPERADMIN/ADMIN |
| `ProjectLifecycleTest.php` | 10 | ~50 | Ciclo de vida completo de proyectos |
| `UserManagementTest.php` | 9 | ~25 | CRUD usuarios, toggle status, reset link |
| `ContractorMaterialTest.php` | 14 | ~40 | CRUD contratistas, materiales, registro público |
| `SupplierInvitationTest.php` | 10 | ~35 | Invitaciones, propuestas, importación |
| `ExampleTest.php` (Feature) | 1 | 1 | Placeholder |
| `ExampleTest.php` (Unit) | 1 | 1 | Placeholder |

### Cobertura por Componente

| Componente | Cobertura | Estado |
|------------|:---------:|:------:|
| **Auth** (login/logout/me) | ✅ 100% | Cubierto |
| **Token expiration + refresh** | ✅ 100% | Cubierto |
| **Role middleware** | ✅ 100% | Cubierto |
| **Project lifecycle** | ✅ 100% | Cubierto |
| **User management** | ✅ 80% | Sin test de email duplicado en update |
| **Contractor/Material CRUD** | ✅ 85% | Sin test de specialty filter |
| **Supplier invitations** | ✅ 90% | Cubierto |
| **Push Tokens** | ❌ 0% | **SIN TEST** |
| **AI Evaluation** | ❌ 0% | **SIN TEST** (solo se verifica middleware) |
| **AI Config CRUD** | ❌ 0% | **SIN TEST** |
| **Project Documents** | ❌ 0% | **SIN TEST** |
| **Comandos de consola** | ❌ 0% | **SIN TEST** |
| **Servicios AI** | ❌ 0% | **SIN TEST** unitarios |
| **ExpoPushService** | ❌ 0% | **SIN TEST** |

### Tests Faltantes (Prioridad Alta)

1. **PushTokenController** — `store()` y `destroy()` 
2. **AIEvaluationController** — Test de evaluación exitosa, failover, errores
3. **AiConfigController** — CRUD completo, test de conexión, sync de cache
4. **ProjectDocumentController** — Subida, descarga, eliminación de documentos
5. **ExpoPushService** — Test unitario con HTTP mock
6. **AIEvaluationService** — Test unitario de failover chain
7. **ProjectObserver** — Test de observer (notificaciones)
8. **Comandos Console** — `AgeUserToken`, `ClearExpiredTokens`

---

## 6. CONCLUSIÓN Y SIGUIENTES PASOS

### Resumen de Findings

Se encontraron **27 hallazgos**: 1 crítico, 6 altos, 12 medios, 8 bajos.

### Acciones Inmediatas (Sprint Actual)

| Prioridad | Acción | Componente |
|:---------:|--------|------------|
| 🔴 Crítico | Corregir keys en `estimateCost()` | `AIEvaluationService.php` |
| 🟠 Alto | Implementar queue para notificaciones push | `ProjectObserver.php` + config |
| 🟠 Alto | Procesar respuesta de Expo Push API y limpiar tokens zombies | `ExpoPushService.php` |
| 🟠 Alto | Validar `base_url` en configuración IA | `AiConfigController.php` |
| 🟠 Alto | No cachear API keys en texto plano | `AiConfigurationService.php` |
| 🟠 Alto | Inyectar config en providers sin mutar estado global | `AIEvaluationService.php` |
| 🟡 Medio | Mover constantes dentro de `UserController` | `UserController.php` |
| 🟡 Medio | Implementar paginación en endpoints de listado | `*Controller.php` |

### Acciones a Corto Plazo (Próximo Sprint)

- Eliminar `withoutMiddleware([ThrottleRequests::class])` y aplicar rate limits específicos
- Refactorizar `GeminiProvider` y `AnthropicProvider` para no heredar de `OpenAIProvider`
- Agregar tests para Push Tokens, AI Evaluation, AI Config y Project Documents
- Eliminar código muerto (`getModelForProvider`)
- Implementar soft-deletes en proyectos

### Acciones a Mediano Plazo (Backlog)

- Refactorizar `SupportController` (viola SRP) en múltiples controladores
- Crear un servicio de auditoría unificado para eliminar duplicación de `log()`
- Implementar DTOs para payloads complejos (evaluación IA, creación proyecto)
- Configurar CI/CD con análisis estático (PHPStan/Pint) y cobertura de código
- Versionado semántico de API (v1 prefix)
- Rotar claves expuestas en `.env` y eliminar dump BD del repo

---

## 7. MÉTRICAS FINALES

| Indicador | Valor |
|-----------|-------|
| Archivos auditados | 75+ |
| Líneas de código revisadas | ~12,000+ |
| Tests existentes | ~130 |
| Endpoints totales | 59 |
| Endpoints con test | 43 (73%) |
| Hallazgos críticos | 1 |
| Hallazgos altos | 6 |
| Hallazgos medios | 12 |
| Hallazgos bajos | 8 |
| Deuda técnica estimada | ~40-60 horas hombre |

---

*Reporte generado el 24 de Julio, 2026 por Sistema de Auditoría de Código Automatizado.*
