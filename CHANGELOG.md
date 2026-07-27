# CHANGELOG

## [2026-07-27] — 🔴 ALTO A-6: Guardas de estado en ciclo de vida de proyecto (integridad financiera)

**Tipo:** security + fix

**Qué:** `ProjectController::selectContractor()`, `pay()`, `reportFinished()` y `verifyCompletion()` no validaban el `status` del proyecto antes de ejecutar la transición — solo el middleware `role:` verificaba *quién* podía llamar, nunca *cuándo* era válido. Se agregó `abort_unless($project->status === ..., 422, ...)` a cada uno, mismo patrón que ya usaba `rejectProposals()`:
- `selectContractor()` → requiere `COMPARATIVA_ENVIADA`.
- `pay(ADVANCE)` → requiere `CONTRATADO`. `pay(FINAL)` → requiere `LISTO_PAGO_FINAL`.
- `reportFinished()` → requiere `EN_EJECUCION`.
- `verifyCompletion()` → requiere `VERIFICANDO_FINALIZACION`.

**Por qué / causa raíz:** Auditoría V3 (A-6/Sec. 3) — un usuario con rol `FINANZAS` podía invocar `POST /projects/{id}/payments` con `paymentType=FINAL` sobre un proyecto recién `CREADO` (saltando adjudicación, ejecución y verificación de calidad). Peor: `paymentType=ADVANCE` sobre un proyecto ya `COMPLETADO_PAGADO` actualizaba el pago existente y el `update()` posterior **revertía el status a `EN_EJECUCION`**, reabriendo un proyecto ya cerrado y pagado sin ninguna restricción de BD que lo impidiera.

**Archivos:** `app/Http/Controllers/Api/ProjectController.php`, `tests/Feature/ProjectLifecycleTest.php`

**Verificación:** 6 tests nuevos cubriendo exactamente los escenarios de la auditoría (pago sobre proyecto `CREADO`, reapertura de proyecto `COMPLETADO_PAGADO`, cada transición fuera de orden). 144/144 tests pasando.

## [2026-07-27] — MEDIUM #15–#17: nuevos endpoints de configuración (permisos, modelos IA, roles)

**Tipo:** feature

**Qué:**
- `GET /api/auth/permissions` (`AuthController::permissions`) — matriz rol→rutas SPA, desde `config/permissions.php` (nuevo). Reemplaza el `roleAccess` que el frontend tenía hardcodeado.
- `GET /api/ai/config/models` (`AiConfigController::availableModels`) — modelos seleccionables por proveedor, desde `config/ai.php` → `available_models` (nuevo). Reemplaza `PROVIDER_MODELS` hardcodeado en el frontend.
- `GET /api/roles` (`UserController::roles`) — devuelve `VALID_ROLES`, ya existente como constante de clase. Reemplaza el array `ROLES` hardcodeado en `UsuariosPanel.tsx` (que además le faltaba `CATALOGOS`).
- Los 3 endpoints viven dentro de grupos ya protegidos por `auth:sanctum` (permisos: cualquier autenticado; roles y modelos IA: `role:SUPERADMIN,ADMIN`, mismo grupo que el resto de configuración).

**Por qué / causa raíz:** ítems MEDIUM #15–#17 de la re-auditoría V2 del frontend — cada uno de estos valores estaba duplicado como constante hardcodeada en el bundle del SPA, requiriendo un deploy de frontend para cualquier cambio (nuevo rol, nuevo modelo, cambio de permisos).

**Archivos:** `routes/api.php`, `app/Http/Controllers/Api/AuthController.php`, `app/Http/Controllers/Api/AiConfigController.php`, `app/Http/Controllers/Api/UserController.php`, `config/permissions.php` [NUEVO], `config/ai.php`, `tests/Feature/AuthTest.php`, `tests/Feature/UserManagementTest.php`, `tests/Feature/AiConfigModelsTest.php` [NUEVO]

**Verificación:** 138/138 tests pasando.

## [2026-07-27] — C-03: HSTS, upgrade-insecure-requests, TRUSTED_PROXIES y forceScheme(https)

**Tipo:** security

**Qué:**
- `AddCspHeaders`: agrega `upgrade-insecure-requests` a la CSP y el header `Strict-Transport-Security: max-age=31536000; includeSubDomains` en todas las respuestas de la API (enviarlo sobre HTTP no tiene efecto por spec, seguro incluirlo siempre).
- `TrustProxies`: ahora lee `config('app.trusted_proxies')` (nueva key `trusted_proxies` en `config/app.php`, vía `env('TRUSTED_PROXIES')` — no `env()` directo en el middleware, para no romperse con `config:cache`). Sin esto, si el backend está detrás de un reverse proxy/CDN que termina TLS, Laravel nunca detecta `$request->isSecure()` correctamente y el HSTS/cookies Secure quedan inertes.
- `AppServiceProvider::boot()`: en producción, `URL::forceScheme('https')` para que cualquier URL generada (ej. links de reset de contraseña) sea siempre https.

**Por qué / causa raíz:** PENDIENTES CRITICAL #3 (auditoría interna) — las contraseñas viajan en claro en el body de `/login`; el control real no es hashear en cliente (evaluado y descartado, no añade seguridad sobre TLS) sino garantizar que la conexión sea siempre HTTPS y que el backend pueda detectarlo correctamente detrás de cualquier proxy de producción.

**Archivos:** `app/Http/Middleware/AddCspHeaders.php`, `app/Http/Middleware/TrustProxies.php`, `app/Providers/AppServiceProvider.php`, `config/app.php`, `.env.example`

**Verificación:** 131/131 tests pasando.

## [2026-07-27] — 🔴 CRITICAL C-02 (rehecho): Sanctum SPA nativo (session + CSRF) reemplaza cookie casera con CSRF roto

**Tipo:** security

**Qué:** Se descartó un intento previo sin commitear (`TokenFromCookie`, `RefreshSanctumToken` cookie-side, `CorsDiagnosticController`) que migraba el token de `localStorage` a cookie httpOnly pero requería `SameSite=None` sin ningún token CSRF — CSRF explotable sobre `pay()`, `approve-investment`, `toggle-status`, etc. Se reemplazó por el flujo oficial de Sanctum SPA, ya parcialmente andamiado en el proyecto (`config/sanctum.php` con `'guard' => ['web']`, `cors.php` ya incluía `sanctum/csrf-cookie`):
- `Kernel.php`: activado `EnsureFrontendRequestsAreStateful` en el grupo `api` (estaba comentado).
- `config/cors.php`: `supports_credentials => true`.
- `AuthController@login`/`@logout`: bifurcan por `$request->hasSession()` — si la petición viene del SPA (Referer/Origin en `SANCTUM_STATEFUL_DOMAINS`), autentica por sesión httpOnly sin exponer token en el body; si no (mobile), sigue emitiendo Bearer token exactamente como antes. `RefreshSanctumToken` (refresh de token mobile) no requirió cambios — ya era un no-op seguro para sesiones vía su chequeo de `TransientToken`.

**Por qué / causa raíz:** Auditorías V3 paralelas de frontend y backend (`AUDITORIA_back_27_07_2026_V3.md`, `AUDITORIA_front_27_07_2026_V3.md`) detectaron, de forma independiente desde ambos lados del stack, el mismo CSRF explotable en el intento anterior.

**Archivos:** `app/Http/Kernel.php`, `config/cors.php`, `app/Http/Controllers/Api/AuthController.php`, `tests/Feature/AuthTest.php`, `.env.example`

**Verificación:** 131/131 tests pasando (incluye 4 tests nuevos del flujo de sesión SPA: login sin token expuesto, `/api/user` autenticado por cookie, logout invalida sesión).

## [2026-07-24] — Documentación de pruebas para fixes Type-A

**Tipo:** docs

**Qué:** Se creó `DOCS/PRUEBAS_AUDITORIAS_TYPE-A.md` con procedimientos detallados para verificar los 7 fixes de severidad crítica y alta. Incluye pruebas unitarias, de integración y manuales para cada fix.

**Adicional:** `test-fixes.sh` script automatizado que verifica 7 puntos clave de los fixes sin necesidad de servidor corriendo.

**Archivos:** `DOCS/PRUEBAS_AUDITORIAS_TYPE-A.md`, `test-fixes.sh`

## [2026-07-24] — M-01: Constantes globales movidas a clase UserController

**Tipo:** refactor

**Qué:** `VALID_ROLES` y `VALID_STATUSES` estaban como constantes globales (fuera de la clase). Se movieron como `private const` dentro de `UserController`.

**Por qué:** Las constantes globales contaminan el namespace global, no son encapsuladas y pueden causar colisiones.

**Archivos:** `app/Http/Controllers/Api/UserController.php`

## [2026-07-24] — M-02: Rate limiting inadecuado en rutas autenticadas

**Tipo:** fix / security

**Qué:** 5 rutas autenticadas eliminaban `ThrottleRequests` completamente sin sustituto. Se creó un rate limiter `catalog` (200 req/min por usuario) y se aplicó a esas rutas, reemplazando el `withoutMiddleware` desnudo por `withoutMiddleware + middleware('throttle:catalog')`.

**Rutas afectadas:**
- `GET /api/modules`
- `GET /api/contractors`
- `GET /api/materials`
- `GET /api/audit-logs`
- `GET /api/projects/{project}/documents`

**Archivos:** `routes/api.php`, `app/Providers/RouteServiceProvider.php`

## [2026-07-24] — M-03: Paginación en endpoints de listado

**Tipo:** feature / performance

**Qué:** Se implementó paginación (`paginate()`) en los 4 endpoints de listado que devolvían todos los registros sin límite. Cada uno acepta `?per_page=` con un máximo capping para evitar abuso.

| Endpoint | Default | Max |
|----------|--------|-----|
| `GET /api/projects` | 20 | 100 |
| `GET /api/users` | 20 | 100 |
| `GET /api/audit-logs` | 50 | 200 |
| `GET /api/supplier-material-proposals` | 20 | 100 |

**Archivos:** `app/Http/Controllers/Api/ProjectController.php`, `app/Http/Controllers/Api/UserController.php`, `app/Http/Controllers/Api/SupportController.php`

## [2026-07-24] — M-04: IDs auto-generados con posibles colisiones en concurrencia

**Tipo:** fix

**Qué:** Se corrigieron 5 puntos de generación de IDs que podían colisionar bajo concurrencia:

| # | Método | Problema | Fix |
|---|--------|----------|-----|
| 1 | `ProjectController::nextProjectId()` | SELECT max → INSERT sin bloqueo | `lockForUpdate()` en la SELECT |
| 2 | `ProjectController::addProposal()` | `'PROP-' . now()->format('Hisv')` colisiona en mismo ms | Se agregó sufijo `-` . `Str::random(4)` |
| 3 | `ProjectController::log()` | `'LOG-' . now()->format('YmdHisv')` colisiona en mismo ms | Se agregó sufijo `-` . `Str::random(4)` |
| 4 | `SupportController::nextContractorCode()` | SELECT max → INSERT sin bloqueo | `lockForUpdate()` + `DB::transaction()` |
| 5 | `SupportController::nextProposalId()` | SELECT max → INSERT sin bloqueo | `lockForUpdate()` + `DB::transaction()` |
| 6 | `ContractorController::nextContractorCode()` | SELECT max → INSERT sin bloqueo | `lockForUpdate()` + `DB::transaction()` |

**Archivos:** `app/Http/Controllers/Api/ProjectController.php`, `app/Http/Controllers/Api/SupportController.php`, `app/Http/Controllers/Api/ContractorController.php`

## [2026-07-24] — M-05: Test `test_token_works_with_device_name` con bug lógico

**Tipo:** fix / tests

**Qué:** El test hacía un primer login con `user@test.com` (usuario que no existía en ese test) sin verificar la respuesta, y luego un segundo login con `device@test.com` que sí pasaba. Se eliminó el primer login irrelevante, dejando solo la llamada correcta con su assertion.

**Archivos:** `tests/Feature/AuthTest.php`

## [2026-07-24] — M-06: GeminiProvider y AnthropicProvider con herencia incorrecta

**Tipo:** refactor

**Qué:** Se creó `BaseAIProvider` (abstracta) que implementa `AIProviderInterface` y contiene los métodos comunes: constructor, `buildSystemPrompt()`, `buildUserPrompt()`, `sanitizeInput()`, `parseResponse()`, `normalizeResult()`. Cada provider ahora extiende `BaseAIProvider` independientemente con sus props `defaultModel()`, `defaultBaseUrl()`, `defaultMaxTokens()`, `name()` y `evaluate()`.

**Antes:** `GeminiProvider extends OpenAIProvider`, `AnthropicProvider extends OpenAIProvider` — heredaban e invalidaban casi todo.

**Ahora:** Los 3 extienden `BaseAIProvider` con herencia plana y sin jerarquía artificial.

**Adicional:** Se eliminaron los defaults hardcodeados (`defaultModel()`, `defaultBaseUrl()`, `defaultMaxTokens()`) de los providers. Ahora `model`, `base_url` y `max_tokens` vienen exclusivamente de la BD (con fallbacks solo en `AiConfigurationService::toServiceConfig()`). Único punto de defaults: `AiConfigurationService`.

**Archivos:** `app/Services/AI/Providers/BaseAIProvider.php` (nuevo), `app/Services/AI/Providers/OpenAIProvider.php`, `app/Services/AI/Providers/GeminiProvider.php`, `app/Services/AI/Providers/AnthropicProvider.php`

## [2026-07-24] — M-07: `registerProviders()` inyecta dependencias vía `app()`

**Tipo:** refactor

**Qué:** El constructor de `AIEvaluationService` aceptaba `?AiConfigurationService $configService = null` con `app()` como fallback, escondiendo la dependencia. Se cambió a parámetro obligatorio para que Laravel resuelva automáticamente.

**Archivos:** `app/Services/AI/AIEvaluationService.php`

## [2026-07-24] — M-08: Método `getModelForProvider()` nunca usado

**Tipo:** cleanup

**Qué:** Se eliminó el método privado `getModelForProvider()` que nunca era llamado. Además usaba `config("ai.{$provider}.model")` que ya no tiene efecto desde el fix A-05 (la config global ya no se muta).

**Archivos:** `app/Services/AI/AIEvaluationService.php`

## [2026-07-24] — B-01: Mensaje de error diferenciado permite enumeración de usuarios

**Tipo:** fix / security

**Qué:** El login devolvía mensaje distinto para "credenciales inválidas" vs "cuenta desactivada", permitiendo inferir si un email existe. Ambos casos ahora devuelven el mismo mensaje genérico.

**Archivos:** `app/Http/Controllers/Api/AuthController.php`

## [2026-07-24] — B-02: Magic strings para estados de proyecto

**Tipo:** refactor

**Qué:** `STATUSES` se cambió de lista indexada a mapa asociativo (`'CREADO' => 'CREADO'`). Las 10 ocurrencias de strings literales de estado en el código fueron reemplazadas por `self::STATUSES['ESTADO']`.

**Archivos:** `app/Http/Controllers/Api/ProjectController.php`

## [2026-07-24] — B-03: DTO para payload de evaluación IA

**Tipo:** refactor

**Qué:** Se crearon 3 DTOs tipados (`EvaluationPayload`, `EvaluationProject`, `EvaluationProposal`) para el payload de evaluación IA. El controller ahora construye los DTOs con named arguments (PHP 8.0+) en lugar de arrays asociativos genéricos. El service y providers siguen recibiendo `array` vía `toArray()` para mantener compatibilidad.

**Archivos:** `app/Services/AI/EvaluationPayload.php`, `app/Services/AI/EvaluationProject.php`, `app/Services/AI/EvaluationProposal.php`, `app/Http/Controllers/Api/AIEvaluationController.php`

## [2026-07-24] — B-04: Validación de tipos MIME en subida de documentos

**Tipo:** audit (ya implementado)

**Qué:** La validación de MIME/extensión ya está implementada en `StoreProjectDocumentRequest::validateFileMimeAndExtension()`. Incluye:
- Server-side MIME detection via `finfo` (`$file->getMimeType()`)
- Extensión vs lista blanca según `document_type` (CALC/PLANO)
- Manejo especial de `application/octet-stream` (solo dwg/dxf)
- Manejo especial de `application/zip` (solo xlsx/ods)
- Sanitización de filename contra path traversal en el controller

**Hallazgo cerrado sin cambios.** El código ya cumplía con la validación.

## [2026-07-24] — B-05: Test `test_remove_awarded_proposal_returns_422` sin verificar la causa

**Tipo:** fix / tests

**Qué:** El test solo verificaba `assertStatus(422)` sin confirmar que el error correspondía a "propuesta adjudicada". Se agregó `assertJsonFragment()` sobre el mensaje específico.

**Archivos:** `tests/Feature/ProjectLifecycleTest.php`

## [2026-07-24] — B-06: Nombres inconsistentes en `toArray()` vs `$fillable`

**Tipo:** docs

**Qué:** Se agregó docblock explicativo en `AiConfiguration::toArray()` aclarando que `toArray()` retorna camelCase para la API, mientras que `$fillable` usa snake_case para la BD. Es intencional por convención del proyecto.

**Archivos:** `app/Models/AiConfiguration.php`

## [2026-07-24] — Queue para notificaciones push (A-01)

**Tipo:** fix / performance

**Qué:** Las notificaciones push ahora se encolan vía `QUEUE_CONNECTION=database` en lugar de ejecutarse sincrónicamente.

**Cambios:**
- `ProjectStatusChanged` implementa `ShouldQueue` → se encola automáticamente
- Migración `create_jobs_table` para la tabla de jobs (estructura estándar de Laravel)
- `QUEUE_CONNECTION=sync` → `database` en `.env`
- Schedule `queue:work --stop-when-empty` cada minuto en `Console/Kernel.php`
- Script `start.sh` para arrancar serve + scheduler juntos

**Por qué (el problema):** `ProjectObserver::updated()` itera usuarios y por cada uno hace un HTTP POST a Expo Push API. Con `QUEUE_CONNECTION=sync`, cada `$user->notify()` se ejecuta en el mismo request. Si hay 100 usuarios → 100 llamadas HTTP secuenciales → el request puede tardar 30-50 segundos o dar timeout. El frontend queda sin respuesta hasta que terminen todas.

**Por qué queue y no otra cosa:**
- **Async (Http::pool):** Envía en paralelo pero el request sigue esperando a que terminen todas. Gana tiempo pero no libera el request.
- **Queue database:** El request solo hace un INSERT (~1ms) y responde al instante. El procesamiento real corre en background via el scheduler. Además da reintentos automáticos si falla (3 intentos) sin afectar al usuario.

**Por qué la tabla `jobs` no crece infinitamente:** Los jobs se eliminan automáticamente al procesarse (DELETE). Solo contiene jobs pendientes. Con el scheduler cada minuto, la tabla está siempre vacía o con 1-2 registros en tránsito. No hay acumulación.

**Por qué schedule:work y no un worker daemon:** En entornos Windows/compartidos no se puede mantener un proceso PHP vivo permanentemente. `schedule:work` emula el cron: cada minuto ejecuta `queue:work --stop-when-empty`, que procesa todo lo pendiente y termina. No deja procesos colgados.

**Archivos:** `app/Notifications/ProjectStatusChanged.php`, `database/migrations/2026_07_24_000001_create_jobs_table.php`, `.env`, `app/Console/Kernel.php`, `start.sh`

## [2026-07-24] — ExpoPushService procesa respuesta y elimina tokens zombies (A-02)

**Tipo:** fix / maintenance

**Qué:** `ExpoPushService` ahora captura la respuesta de Expo Push API, detecta errores `DeviceNotRegistered`/`ExponentNotRegistered` y elimina automáticamente esos tokens de la BD.

**Por qué:** Antes se ignoraba la respuesta. Los tokens de dispositivos que desinstalaron la app o expiraron nunca se limpiaban, acumulándose indefinidamente. Cada notificación intentaba enviar a esos tokens zombies, generando llamadas HTTP inútiles y riesgo de rate-limiting por parte de Expo.

**Cómo funciona:**
- Expo Push API responde con un array `data` en el mismo orden que los mensajes enviados
- `processResponse()` itera cada entrada; si `details.error === "DeviceNotRegistered"`, obtiene el token del mensaje original y lo elimina de la BD
- También loguea la eliminación para trazabilidad
- En caso de error HTTP (timeout, 5xx), se loguea la advertencia pero no se reintenta (el queue ya reintentará el job completo)

**Archivos:** `app/Services/ExpoPushService.php`

## [2026-07-24] — Validación SSRF en baseUrl de IA (A-03)

**Tipo:** security

**Qué:** Se agregó validación `ssrfSafeUrl()` al campo `baseUrl` en los endpoints de creación y actualización de configuración IA.

**Por qué:** El campo `base_url` se usaba directamente en llamadas HTTP a proveedores IA. Sin validación, un ADMIN/SUPERADMIN malintencionado o un ataque XSS podría redirigir las peticiones a servicios internos (localhost, IPs privadas) — SSRF (Server-Side Request Forgery).

**Validaciones aplicadas:**
- Solo permite URLs `https://` (nada de HTTP plano)
- Rechaza localhost, 127.0.0.1, 0.0.0.0, ::1
- Rechaza rangos de IPs privadas (10.x.x.x, 172.16-31.x.x, 192.168.x.x)
- Valida que el host sea una dirección o dominio válido

**Archivos:** `app/Http/Controllers/Api/AiConfigController.php`

## [2026-07-24] — API keys no se cachean en texto plano (A-04)

**Tipo:** security

**Qué:** Se separó la API key del resto de la configuración cacheada. El cache ahora solo almacena metadatos no sensibles (modelo, proveedor, activo, etc.). La API key se obtiene directamente de la BD en cada request.

**Cambios:**
- `AiConfigurationService::toServiceConfig()` ya no incluye `api_key` en el array que se persiste en cache
- Nuevo método `getApiKey(string $provider): ?string` que consulta la BD directamente y usa el accessor de Eloquent que desencripta la key
- `AIEvaluationService::registerProviders()` obtiene la API key vía `getApiKey()` en lugar de leerla del cache

**Por qué:** `Cache::forever()` almacena datos en disco/redis/archivos según el driver. Si alguien accede al almacenamiento de cache, podía leer las API keys de todos los proveedores IA en texto plano. Ahora las keys solo están en memoria durante la ejecución del request y nunca se persisten fuera de la BD (donde están encriptadas con `Crypt::encryptString`).

**Archivos:** `app/Services/AI/AiConfigurationService.php`, `app/Services/AI/AIEvaluationService.php`

## [2026-07-24] — Providers IA reciben config por constructor, sin mutar estado global (A-05)

**Tipo:** security / refactor

**Qué:** Se eliminó la mutación de `config(["ai.{$key}" => $config])` en `registerProviders()`. Ahora la configuración se pasa directamente al constructor de cada provider.

**Cambios:**
- `OpenAIProvider::__construct()` ahora acepta `array $config` en lugar de leer de `config()`
- `GeminiProvider` y `AnthropicProvider` igual — heredan y pasan al parent
- `AIEvaluationService::registerProviders()` construye el array de config y lo inyecta en el constructor
- Se eliminaron todas las llamadas a `config()` dentro de los providers

**Por qué:** `config(["ai.{$key}" => $config])` muta el estado global de Laravel. En entornos concurrentes (php-fpm con múltiples workers, Swoole, ReactPHP), una request podía ver la configuración de IA de otra request, incluyendo API keys de diferentes proveedores. Al pasar la config por constructor, cada provider es autocontenido y no depende del estado global.

**Archivos:** `app/Services/AI/AIEvaluationService.php`, `app/Services/AI/Providers/OpenAIProvider.php`, `app/Services/AI/Providers/GeminiProvider.php`, `app/Services/AI/Providers/AnthropicProvider.php`

## [2026-07-24] — Fix crítico: estimateCost() con keys incorrectas

**Tipo:** fix / critical

**Qué:** Las keys del array `$pricing` en `estimateCost()` usaban `chatgpt`/`claude` pero el método recibe `openai`/`anthropic`. Corregido a `openai`/`anthropic`. También se fixeó indentación inconsistente en línea 234.

**Por qué causaba:** Todos los costos estimados de IA se registraban como $0 en `AiUsageLog`, imposibilitando cualquier análisis de costos real desde que se implementó el sistema.

**Archivos:** `app/Services/AI/AIEvaluationService.php`

## [2026-07-24] — Filtro de notificaciones push por rol en ProjectObserver

**Tipo:** fix / performance

**Qué:** Se reemplazó `User::all()` por filtro basado en matriz de roles según el nuevo estado del proyecto. `LISTO_PAGO_FINAL` queda como estado técnico sin notificaciones.

**Por qué:** Evita spam de notificaciones a usuarios irrelevantes y reduce drásticamente llamadas HTTP a Expo API (de N usuarios a ~2-6 según el evento).

**Archivos:** `app/Observers/ProjectObserver.php`

**Matriz implementada:**
| Estado | Notificados |
|---|---|
| CREADO | CIERRE_DE_OBRA, SUPERADMIN, ADMIN |
| REVISADO_CIERRE | PROCURA, SUPERADMIN, ADMIN |
| CONFIRMADO_PROCURA | ANALISTA, SUPERADMIN, ADMIN |
| COMPARATIVA_ENVIADA | PROCURA, SUPERADMIN, ADMIN |
| CONTRATADO | FINANZAS, CIERRE_DE_OBRA, INFRAESTRUCTURA, PRESIDENCIA, SUPERADMIN, ADMIN |
| EN_EJECUCION | CIERRE_DE_OBRA, INFRAESTRUCTURA, PRESIDENCIA, SUPERADMIN, ADMIN |
| VERIFICANDO_FINALIZACION | SUPERADMIN, ADMIN |
| LISTO_PAGO_FINAL | (ninguno — estado técnico) |
| COMPLETADO_PAGADO | CIERRE_DE_OBRA, INFRAESTRUCTURA, PRESIDENCIA, SUPERADMIN, ADMIN |

## [2026-07-24] — V2 Auditoría integral backend + 27 hallazgos documentados

**Tipo:** docs / security / code-quality

**Qué:** Segunda auditoría integral del backend. Se evaluaron 75+ archivos. **27 hallazgos totales** (1 crítico, 6 altos, 12 medios, 8 bajos). Reporte completo en `AUDITORIA_back_24_07_2026_V2.md`.

**Hallazgos nuevos/clasificados:**

🔴 **Crítico (1):**
- C-01: `estimateCost()` usa keys 'chatgpt'/'claude' pero recibe 'openai'/'anthropic' → costos IA siempre $0

🟠 **Altos (6):**
- A-01: Notificaciones push bloquean el request HTTP (QUEUE_CONNECTION=sync)
- A-02: `ExpoPushService` ignora respuesta API → tokens zombies perpetuos
- A-03: `base_url` en fillable de `AiConfiguration` permite SSRF potencial
- A-04: `AiConfigurationService` cachea API keys desencriptadas en texto plano
- A-05: `config(["ai.{$key}"])` muta estado global (peligro concurrencia)

🟡 **Medios (12):**
- M-01: Constantes en `UserController` definidas fuera de clase
- M-02: Rutas sin rate limiting (`withoutMiddleware([ThrottleRequests])`)
- M-03: Endpoints de listado sin paginación
- M-04: IDs auto-generados colisionables bajo concurrencia
- M-05: Bug lógico en test `test_token_works_with_device_name`
- M-06: Herencia incorrecta en Gemini/AnthropicProvider (extienden OpenAIProvider)
- M-07: `registerProviders()` inyecta dependencias vía `app()` en constructor
- M-08: `getModelForProvider()` es código muerto (nunca usado)
- M-09: `.env` expone API keys + dump BD versionado

🔵 **Bajos (8):**
- B-01: Mensajes de error permiten enumeración de usuarios
- B-02: Magic strings para estados de proyecto
- B-03: Sin DTO para payload de evaluación IA
- B-04: Sin validación de tipos MIME en subida de documentos
- B-05: Test débil de `test_send_reset_link`
- B-06: Inconsistencia `camelCase`/`snake_case` en `AiConfiguration`
- B-07: Sin tests para PushToken, AI Evaluation, AI Config, ProjectDocuments
- B-08: `log()` duplicado en 3 controladores (violación DRY)

**Porcentaje de remediación:** 10% (3 de 30 hallazgos previos corregidos según V1)

## [2026-07-24] — Auditoría integral backend (75+ archivos, 50+ hallazgos) [V1]

**Tipo:** docs / security

**Qué:** Auditoría completa del backend. 50+ hallazgos totales (12 críticos, 15 altos, 20 medios, 10 bajos). **5 críticos nuevos** no documentados previamente:

- **C-02**: `ProjectObserver::updated()` notifica a TODOS los usuarios sincrónicamente (spam/DoS)
- **C-03**: Notificaciones push bloquean el request HTTP (sin queue)
- **C-04**: `estimateCost()` en AIEvaluationService con keys incorrectas → costos siempre $0
- **C-05**: `config(["ai.{$key}"])` muta estado global (peligro en entornos concurrentes)
- **C-06**: ExpoPushService ignora respuesta de API → tokens zombies perpetuos
- **C-07**: `AiConfiguration::base_url` en fillable permite SSRF
- **C-08**: `AiConfigurationService` cachea API keys desencriptadas en texto plano

**Hallazgos altos nuevos:** IDs con timestamp colisionables, race conditions en generadores, invitaciones sin expiración, Gemini API key en URL query param, PushToken sin unique constraint.

**Estado de previos:** A-01 (roles en rutas), BD-01 (FK length), C-01 (typo attempLog), S-04 (rate limiting) → ✅ FIXED. S-01, S-05 (keys en git), S-03 (APP_DEBUG), S-07 (password policy) → ❌ ABIERTOS.

**Archivos:**
- `AUDITORIA_INTERNA_BACK.md` — actualizado con todos los hallazgos nuevos

---

## [2026-07-23] — Tests: fix sintaxis models + RefreshSanctumToken TransientToken guard + actingAs en tests

**Tipo:** fix / test

**Qué:**
1. **Syntax error en 8 modelos**: Las clases tenían `use HasFactory;{` (duplicación de `{`) que causaba `ParseError`. Eliminado `{` extra en todos.
2. **TransientToken guard en RefreshSanctumToken**: Middleware asumía `$token->created_at` existía, pero `actingAs()` en tests usa `TransientToken` sin `created_at`. Agregado early return si token es `instanceof TransientToken`.
3. **Tests migrados de `withHeaders(createToken)` a `actingAs()`**: `createToken('test')` con mismo nombre causaba colisión de autenticación entre requests. Todos los tests ahora usan `$this->actingAs($user)` (vía TransientToken) que es el estándar de Laravel.
4. **Assertions corregidas**: float/int en `approvedInvestmentAmount`, datos determinísticos en filtro `type`.

**Tests:** 128 tests, 342 assertions — OK.

**Archivos:**
- `app/Models/Project.php`, `Contractor.php`, `MaterialCatalog.php`, `ProjectProposal.php`, `SupplierInvitation.php`, `SupplierMaterialProposal.php`, `AuditLog.php`, `ProjectMaterial.php`
- `app/Http/Middleware/RefreshSanctumToken.php`
- `tests/Feature/ProjectLifecycleTest.php`
- `tests/Feature/RoleMiddlewareTest.php`

---

## [2026-07-23] — Middleware role en 12 rutas críticas (A-01)

**Tipo:** security

**Qué:** Aplicado middleware `role:X,ADMIN,SUPERADMIN` a 12 endpoints que carecían de restricción de rol, permitiendo que cualquier usuario autenticado ejecutara acciones sensibles (aprobar inversiones, pagar, adjudicar contratos, etc.).

**Causa raíz:** Las rutas estaban protegidas solo con `auth:sanctum` + `refresh.token`, sin verificar el rol del usuario. El middleware `CheckRole` existía pero no se aplicaba a estas rutas.

**Cambios en `routes/api.php`:**
| Endpoint | Roles |
|----------|-------|
| `POST /projects/{project}/review` | `CIERRE_DE_OBRA, ADMIN, SUPERADMIN` |
| `POST /projects/{project}/approve-investment` | `PROCURA, ADMIN, SUPERADMIN` |
| `POST /projects/{project}/proposals` | `ANALISTA, ADMIN, SUPERADMIN` |
| `DELETE /projects/{project}/proposals/{proposal}` | `ANALISTA, ADMIN, SUPERADMIN` |
| `POST /projects/{project}/submit-comparative` | `ANALISTA, ADMIN, SUPERADMIN` |
| `POST /projects/{project}/import-supplier-proposals` | `ANALISTA, ADMIN, SUPERADMIN` |
| `POST /projects/{project}/reject-proposals` | `PROCURA, ADMIN, SUPERADMIN` |
| `POST /projects/{project}/select-contractor` | `PROCURA, ADMIN, SUPERADMIN` |
| `POST /projects/{project}/payments` | `FINANZAS, ADMIN, SUPERADMIN` |
| `POST /projects/{project}/report-finished` | `CIERRE_DE_OBRA, ADMIN, SUPERADMIN` |
| `POST /projects/{project}/verify-completion` | `CIERRE_DE_OBRA, ADMIN, SUPERADMIN` |
| `POST /ai/evaluate-proposals` | `PROCURA, ADMIN, SUPERADMIN` |

**Archivos:**
- `routes/api.php`

---

## [2026-07-23] — Fix FK type mismatch project_documents.project_id (BD-01)

**Tipo:** fix

**Qué:** Corregido type mismatch en FK `project_documents.project_id` que estaba definido como `VARCHAR(20)` mientras la PK referenciada `projects.id` es `VARCHAR(40)`.

**Causa raíz:** Migration original `2026_07_01_200000_create_project_documents_table.php` definió `string('project_id', 20)` por error. La FK existente en la BD apuntaba a una columna PK de mayor tamaño, lo que constituye una inconsistencia de esquema y puede causar errores en otros DB engines o si futuros IDs superan 20 caracteres.

**Cambios:**
- `database/migrations/2026_07_01_200000_create_project_documents_table.php`: `string('project_id', 20)` → `string('project_id', 40)`
- `database/migrations/2026_07_23_000001_fix_project_id_length_in_project_documents.php` [NUEVO]: Altera columna existente de VARCHAR(20) a VARCHAR(40) y recrea FK.

**Archivos:**
- `database/migrations/2026_07_01_200000_create_project_documents_table.php`
- `database/migrations/2026_07_23_000001_fix_project_id_length_in_project_documents.php` [NUEVO]

---

## [2026-07-20] — Seguridad y validación en carga de archivos

**Tipo:** security

**Qué:** Validación real de MIME type (server-side finfo) + extensión por tipo de documento, sanitización de nombres de archivo, y manejo de colisiones en endpoint `POST /projects/{project}/documents`.

**Causa raíz:** Las constantes `ALLOWED_CALC_MIMES` y `ALLOWED_PLANO_MIMES` existían pero nunca se aplicaban en la validación. El nombre original del cliente se usaba directamente en `storeAs` (path traversal). Archivos duplicados sobrescribían el existente sin advertencia.

**Cambios:**

1. **FormRequest `StoreProjectDocumentRequest`** (nuevo):
   - Valida MIME type detectado por servidor (`finfo`) contra lista permitida según `document_type`
   - Valida extensión del archivo contra lista permitida
   - Caso especial: `application/octet-stream` solo aceptado si extensión es `.dwg` o `.dxf` (PLANO)
   - Elimina imports y validación inline del controlador

2. **Controlador `ProjectDocumentController`**:
   - `upload()` ahora type-hint `StoreProjectDocumentRequest` en lugar de `Request`
   - Sanitización de filename (`sanitizeFilename`): remueve path traversal (`basename`), null bytes, caracteres no seguros, normaliza UTF-8, colapsa separadores, fallback si queda vacío
   - Colisiones evitadas (`uniqueFilename`): si el archivo ya existe en el directorio, se añade sufijo timestamp (`_20260720111500`)
   - Se eliminaron las constantes MIME duplicadas (ahora en FormRequest)

**Archivos:**
- `app/Http/Requests/StoreProjectDocumentRequest.php` — [NUEVO]
- `app/Http/Controllers/Api/ProjectDocumentController.php` — refactor validación + sanitización + colisiones

**Vulnerabilidades cerradas:**
- S-06 (MIME type no validado)
- Path traversal vía `getClientOriginalName()` con `../`
- Sobrescritura de archivos en disco por nombre duplicado
- Inyección de caracteres de control en nombre de archivo

---

## [2026-07-20] — Expiración de tokens Sanctum + renovación silenciosa + limpieza

**Tipo:** feature / security

**Qué:** Implementación completa de expiración automática de tokens de API, renovación silenciosa con rotación, y limpieza programada de tokens expirados.

**Detalle:**

**Fase 1 — Configurar expiración (config-based):**
- `config/sanctum.php`: `'expiration' => env('SANCTUM_EXPIRATION', 1440)` — 24h por defecto
- Sanctum Guard valida automáticamente `created_at + expiration` en cada request
- Aplica a tokens nuevos y existentes (basado en `created_at`)

**Fase 2 — Renovación silenciosa con rotación (`RefreshSanctumToken` middleware):**
- Middleware que corre después de `auth:sanctum` en todas las rutas protegidas
- Detecta tokens a menos de 60 min de su expiración config-based
- Crea un nuevo token (con nombre y abilities preservados), elimina el viejo
- Devuelve el nuevo token en header `X-Refresh-Token`
- El cliente debe reemplazar su token almacenado al recibir este header

**Fase 4 — Limpieza programada de tokens expirados:**
- Nuevo comando `sanctum:clear-expired-tokens` que elimina tokens con `expires_at` en pasado
- Programado diariamente via `app/Console/Kernel.php`

**Archivos:**
- `config/sanctum.php` — expiration de null → 1440 (configurable via env)
- `app/Http/Middleware/RefreshSanctumToken.php` — [NUEVO] middleware de rotación
- `app/Http/Kernel.php` — registro de middleware `refresh.token`
- `routes/api.php` — middleware `refresh.token` aplicado al grupo `auth:sanctum`
- `app/Console/Commands/ClearExpiredTokens.php` — [NUEVO] comando artisan
- `app/Console/Kernel.php` — schedule diario de limpieza
- `phpunit.xml` — SQLite in-memory habilitado para tests
- `tests/Feature/TokenExpirationTest.php` — [NUEVO] 6 tests (expiración, refresh, limpieza, rotación)
- `docs/plan-concurrencia-refresh.md` — [NUEVO] plan de fix para concurrencia

---

## [2026-07-20] — Fix concurrencia en refresh de tokens (grace period 60s)

**Tipo:** fix

**Qué:** Los tokens refrescados tenían eliminación inmediata del token viejo, causando 401 en requests concurrentes que usaban el token original durante la ventana de refresh.

**Causa raíz:** `$token->delete()` en el middleware eliminaba el token antes de que requests en vuelo pudieran completar su autenticación.

**Solución: Grace period de 60 segundos**
- En vez de eliminar el token viejo, se asigna `expires_at = now() + 60s`
- Ambos tokens (viejo + nuevo) son válidos durante 60s
- Sanctum Guard valida dos condiciones (AND): config-based por `created_at`, column-based por `expires_at`
- El token viejo pasa la config check aún por ~30min (refresh se gatilla con 60 min de holgura)
- El token viejo pasa la column check porque su nuevo `expires_at` está 60s en el futuro

**Mejora adicional en cleanup:**
- `ClearExpiredTokens` ahora también limpia tokens sin `expires_at` cuyo `created_at` ya excedió la ventana de expiración config-based
- Estos tokens (creados por login, nunca refrescados) antes quedaban huérfanos en BD

**Archivos:**
- `app/Http/Middleware/RefreshSanctumToken.php` — delete → grace period 60s
- `app/Console/Commands/ClearExpiredTokens.php` — también limpia config-expired sin expires_at
- `tests/Feature/TokenExpirationTest.php` — tests actualizados a grace period
- `tests/Feature/TokenExpirationIntegrationTest.php` — tests actualizados a grace period

---

## [2026-07-17] — Feature: Endpoint importación automática de propuestas de proveedores

**Tipo:** feature

**Qué:** Nuevo endpoint `POST /api/projects/{project}/import-supplier-proposals` que importa las `SupplierMaterialProposal` de un proyecto como `ProjectProposal` en el cuadro comparativo de Analistas.

**Lógica del endpoint:**
1. Busca todas `SupplierMaterialProposal` donde `project_id = {project}`
2. Por cada una, busca el `Contractor` correspondiente:
   - Match por `supplier_contact` → `contractor.contact`
   - Fallback match por `supplier_name` → `contractor.name`
3. Si no encuentra contractor → omite con error en array `errors[]`
4. Si el contractor ya tiene una propuesta en el proyecto → omite (deduplicación)
5. Calcula `materialCost` = suma de `items[].totalPrice`
6. Convierte duración a semanas según `duration_unit` (días/7, meses×4, semanas directo)
7. Crea `ProjectProposal` con `negotiated_advance_percent: 30` como default
8. Si `general_notes` está vacío, genera descripción automática
9. Registra auditoría con conteo de importadas/omitidas

**Response:**
```json
{
  "message": "Se importaron 3 propuesta(s).",
  "imported": 3,
  "skipped": 0,
  "errors": [],
  "project": { "...ProjectResource..." }
}
```

**Archivos:**
- `app/Http/Controllers/Api/ProjectController.php` — nuevo método `importSupplierProposals`, import de `SupplierMaterialProposal`
- `routes/api.php` — nueva ruta en grupo `auth:sanctum`

---

## Proyecto: Infraestructura (Laravel + MySQL)

Stack: Laravel (PHP), MariaDB/MySQL, Sanctum.

Estructura clave:
- `ivoo_gestion_infraestructura.sql` — dump de BD exportado desde phpMyAdmin.
- Login via Sanctum -> tabla `personal_access_tokens`.

## [2026-07-16]
- Cambio: Fix error 1364 "Field 'id' doesn't have a default value" en `personal_access_tokens`.
- Causa raíz: El dump SQL definía `id` sin `AUTO_INCREMENT` en la creación de la tabla, y el ALTER TABLE correspondiente (al final del dump) no se ejecutaba porque la importación fallaba antes debido a un error de collation en la vista `vw_project_summary`.
  - El error de collation (#1267) ocurría porque la tabla `contractors` no tenía `COLLATE` explícito, heredando `utf8mb4_general_ci` de la BD, mientras `projects` tenía `utf8mb4_unicode_ci`. El JOIN en la vista fallaba, deteniendo la importación antes de los `ALTER TABLE ... AUTO_INCREMENT`.
- Fix aplicados al dump SQL:
  1. Tabla `contractors`: agregado `ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci`.
  2. Tabla `personal_access_tokens`: se movieron PRIMARY KEY, UNIQUE KEY y KEY directo al CREATE TABLE, y se eliminaron los ALTER TABLE redundantes al final del dump (evita error #1075 por AUTO_INCREMENT sin KEY).
  3. Vista `vw_project_summary`: forzado `collate utf8mb4_unicode_ci` en la condición `c.code = p.selected_contractor_code`.
- Archivos: `ivoo_gestion_infraestructura.sql`

## [2026-07-16] — Implementación Backend Evaluación Inteligente IA

### Cambio: Sistema completo de Evaluación Inteligente de Ofertas (Procura)

Se implementó el backend Laravel que orquesta llamadas a OpenAI (ChatGPT), Google Gemini y Anthropic (Claude) con failover automático.

**Arquitectura:**
- `POST /api/ai/evaluate-proposals` — Endpoint protegido con Sanctum
- `AIEvaluationController` — Valida request, llama al servicio, registra auditoría
- `AIEvaluationService` — Orquestador: prueba proveedores en orden configurado, failover en rate-limit/timeout/error
- `AIProviderInterface` — Contrato que todos los providers implementan
- `OpenAIProvider` — Llama a `chat/completions` con GPT-4o
- `GeminiProvider` — Llama a `models/{model}:generateContent` con Gemini 1.5 Pro
- `AnthropicProvider` — Llama a `/messages` con Claude 3 Opus

**Failover:**
1. OpenAI (ChatGPT) — 1er intento
2. Gemini (Google) — 2do intento si falla
3. Anthropic (Claude) — 3er intento si fallan los anteriores
4. Todos fallan → `AllProvidersExhaustedException` → 503 con mensaje claro

**Prompt Engineering:**
- System prompt con rol de "Ingeniero en Infraestructura con 15 años de experiencia en finanzas y contratación"
- Evalúa: costo total, relación costo-beneficio, plazo, %anticipo, capacidad del contratista, observaciones (tasa USD, garantías, disponibilidad)
- Respuesta estrictamente JSON con: winner, score, summary, strengths, weaknesses, risks, recommendation

**Configuración (`config/ai.php`):**
- Variables de entorno para API keys y modelos
- Orden de proveedores configurable via `AI_PROVIDER_ORDER`
- Timeout configurable por llamada

**Adicional:**
- Migration: columna `observations` en `project_proposals`
- Modelo `ProjectProposal`: `observations` en fillable
- Resource `ProjectResource`: `observations` en serialización
- `ProjectController@addProposal`: acepta campo `observations`
- Auditoría: cada evaluación se registra en `audit_logs` con score, ganador, proveedor usado

**Archivos creados/modificados:**
- `app/Services/AI/Providers/AIProviderInterface.php` — [NUEVO]
- `app/Services/AI/Providers/OpenAIProvider.php` — [NUEVO]
- `app/Services/AI/Providers/GeminiProvider.php` — [NUEVO]
- `app/Services/AI/Providers/AnthropicProvider.php` — [NUEVO]
- `app/Services/AI/AIEvaluationService.php` — [NUEVO]
- `app/Http/Controllers/Api/AIEvaluationController.php` — [NUEVO]
- `config/ai.php` — [NUEVO]
- `database/migrations/2026_07_16_150117_add_observations_to_project_proposals.php` — [NUEVO]
- `routes/api.php` — + ruta POST /api/ai/evaluate-proposals
- `app/Models/ProjectProposal.php` — + observations en fillable/casts
- `app/Http/Resources/ProjectResource.php` — + observations en serialización
- `app/Http/Controllers/Api/ProjectController.php` — + observations en addProposal
- `.env` — + variables OPENAI_API_KEY, GEMINI_API_KEY, ANTHROPIC_API_KEY

---

## [2026-07-16] — Eliminada columna `observations` (redundante)

**Causa:** La columna `observations` en `project_proposals` era redundante ya que `description` cubre el mismo propósito. Los datos contextuales para la evaluación IA (tasa dólar, garantías, etc.) se incluirán en `description`.

**Cambios:**
- Rollback y eliminación de migration `2026_07_16_150117_add_observations_to_project_proposals.php`
- `app/Models/ProjectProposal.php` — eliminado `observations` de `$fillable`
- `app/Http/Resources/ProjectResource.php` — eliminado `observations` de serialización
- `app/Http/Controllers/Api/ProjectController.php` — eliminado `observations` de validación y creación
- `app/Services/AI/Providers/OpenAIProvider.php` — eliminado bloque `observations` del prompt builder

**Archivos:**
- `database/migrations/2026_07_16_150117_add_observations_to_project_proposals.php` — [ELIMINADO]
- `app/Models/ProjectProposal.php`
- `app/Http/Resources/ProjectResource.php`
- `app/Http/Controllers/Api/ProjectController.php`
- `app/Services/AI/Providers/OpenAIProvider.php`

### Backend (Laravel)

**Endpoint mejorado:**
- `POST /api/ai/evaluate-proposals` acepta parámetro opcional `provider` (`chatgpt` | `gemini` | `claude`)
- Si se envía `provider`: usa **solo** ese proveedor (modo forzado, sin failover)
- Si no se envía: failover automático ChatGPT → Gemini → Claude
- Validación con `Rule::in(['chatgpt','gemini','claude'])`

**Archivos actualizados:**
- `app/Http/Controllers/Api/AIEvaluationController.php` — Parámetro `provider` opcional + validación
- `app/Services/AI/AIEvaluationService.php` — Método `evaluateWithProvider()` para modo forzado

---

---

## [2026-07-16] — Feature: Rating del contratista como criterio en evaluación IA

**Tipo:** feature

**Qué:** La IA ahora recibe y evalúa el `rating` del contratista (1.0–5.0) como parte de los datos de cada propuesta, considerándolo como un factor en el análisis para asignar el `confidenceScore`.

**Cambios:**
- `app/Http/Controllers/Api/AIEvaluationController.php`:
  - Enriquecimiento automático de cada propuesta con el `rating` actual del contratista desde la tabla `contractors` (lookup por `contractorCode`)
  - Se eliminó `contractorRating` de validación (ya no depende del frontend, se obtiene desde la BD)
- `app/Services/AI/Providers/OpenAIProvider.php`:
  - `buildSystemPrompt()` — criterio #5: "RATING del contratista (puntuación 1.0–5.0 basada en desempeño histórico, calidad y cumplimiento)"
  - `buildUserPrompt()` — cada propuesta incluye "Rating del Contratista: X/5.0"

**Archivos:**
- `app/Http/Controllers/Api/AIEvaluationController.php`
- `app/Services/AI/Providers/OpenAIProvider.php`

---

---

## [2026-07-16] — Seguridad: Protección contra Prompt Injection en evaluación IA

**Tipo:** security

**Qué:** Se identificó que los campos de texto libre ingresados por usuarios (especialmente `description` de propuestas) se interpolaban directamente en el prompt enviado al modelo de IA sin ninguna sanitización, permitiendo potenciales ataques de prompt injection (cambio de rol, instrucciones maliciosas, jailbreak).

**Vectores identificados:**
- `projectTitle`, `projectDescription`, `projectLocation`, `projectType`
- `contractorName`, `description` (crítico: texto libre del Analista)

**Defensas implementadas (3 capas):**

1. **System prompt reforzado** (`buildSystemPrompt`):
   - Sección `--- SEGURIDAD ---` con instrucción explícita: ignorar cualquier instrucción embebida en los campos de datos, mantener el rol de Ingeniero en Infraestructura, no ejecutar jailbreak.

2. **Sanitización de entradas** (`sanitizeInput` en `buildUserPrompt`):
   - Limpieza de caracteres de control (null bytes, escapes)
   - Neutralización de patrones comunes de injection: `ignore previous instructions`, `forget prompts`, `jailbreak`, `act as if`, `do not follow`, etc. → se reemplazan por `[INYECCION_BLOQUEADA]`
   - Delimitación de campos de texto libre con `[INICIO_DATOS]...[FIN_DATOS]` para separar claramente datos de instrucciones
   - Límite de 2000 caracteres por campo

3. **Validación backend** (`AIEvaluationController`):
   - Límites `max:500`/`max:2000` en campos de texto para evitar payloads desbordados

**Archivos:**
- `app/Services/AI/Providers/OpenAIProvider.php`
- `app/Http/Controllers/Api/AIEvaluationController.php`

---

### Estado Final: Feature Completa ✅

**Para producción solo falta configurar API keys en `.env`:**
```env
OPENAI_API_KEY=sk-...
GEMINI_API_KEY=...
ANTHROPIC_API_KEY=sk-ant-...
```

---

## [2026-07-17] — Auditoría completa de seguridad y calidad

**Tipo:** docs

**Qué:** Auditoría integral del backend Laravel: seguridad, autenticación, BD, código, tests, configuración. 31 hallazgos encontrados.

**Resumen por gravedad:**
- 🔴 CRÍTICO: 5
- 🟠 ALTA: 7
- 🟡 MEDIA: 11
- 🟡 BAJA: 8

**Hallazgos críticos (acción inmediata):**

| ID | Hallazgo | Archivo |
|---|---|---|
| S-01 | GEMINI_API_KEY expuesta en .env commitado | `.env` |
| C-01 | Typo `attempLog` en AIEvaluationService (attemptLog siempre vacío en modo forzado) | `app/Services/AI/AIEvaluationService.php` |
| BD-01 | Type mismatch en FK project_documents.project_id (20 vs 40 chars) | `database/migrations/2026_07_01_200000_create_project_documents_table.php` |
| T-01 | 0 pruebas de aplicación (solo 2 boilerplate) | `tests/` |
| A-01 | Rutas sensibles sin restricción de rol (review, approveInvestment, pay, etc.) | `routes/api.php` |

**Hallazgos de seguridad altos:**
- S-02: CORS permite cualquier origen (`*`)
- S-03: APP_DEBUG=true en .env
- S-04: Sin rate limiting en endpoints públicos
- S-05: APP_KEY expuesta en .env
- S-06: MIME type de archivos subidos no se valida realmente
- S-07: Password policy débil (solo min:8)

**Otros hallazgos notables:**
- GeminiProvider y AnthropicProvider extienden OpenAIProvider (acoplamiento fuerte, causó error en production por método private)
- Form Requests vacío — validación inline en controllers
- Sin soft deletes, sin eventos, sin jobs, sin paginación
- Sin API versioning
- Seeders sin role asignado

**Archivos afectados:** Ver reporte completo en la conversación con el agente.

**Acciones recomendadas:**
1. Rotar GEMINI_API_KEY (está en git history)
2. Restringir CORS y añadir rate limiting a endpoints públicos
3. Implementar MIME validation real en upload de documentos
4. Añadir middleware `role` a rutas sensibles
5. Implementar test suite base
6. Refactorizar providers IA a clase abstracta base

---

## [2026-07-21] — Invitation links single-use + invalidación + tests

**Tipo:** feature / fix

**Qué:** Endpoints públicos de invitación ahora marcan `used_at` al usar el link, invalidan links previos activos al re-invitar, y se agregaron 12 tests de integración.

**Cambios:**
- Migración: columnas `used_at` (timestamp nullable) y `replaced_by` (char(36) nullable) en `supplier_invitations`
- `SupplierInvitation::isValid()` — retorna true solo si `used_at` es null y `replaced_by` es null
- `SupportController@getInvitationPublicInfo` — valida `isValid()`, retorna 404 si inválido
- `SupportController@storeSupplierMaterialProposal` — marca `used_at = now()` al crear propuesta
- `SupportController@createSupplierInvitation` — invalida links previos activos para mismo project_id + supplier_contact
- Fix: enum `type` mayúsculas en tests (`Mantenimiento` → `MANTENIMIENTO`)
- Fix: formato `used_at` en assertDatabaseHas (`Y-m-d H:i` → `Y-m-d H:i:s`)
- `tests/Feature/InvitationLinkTest.php` [NUEVO] — 12 tests

**Archivos:**
- `database/migrations/2026_07_21_000001_add_link_status_to_supplier_invitations.php`
- `app/Models/SupplierInvitation.php`
- `app/Http/Controllers/Api/SupportController.php`
- `tests/Feature/InvitationLinkTest.php`

---

## [2026-07-17] — Carga de 20 propuestas de materiales a BD

**Tipo:** feature

**Qué:** Se crearon las tablas faltantes `supplier_invitations` y `supplier_material_proposals` (existían en migraciones pero no en la BD física) y se insertaron 20 propuestas de materiales realistas distribuidas entre proyectos activos.

**Detalle:**
- Creadas tablas vía SQL directo (migraciones ya estaban marcadas como ejecutadas)
- 12 invitaciones a proveedores vinculadas a proyectos en estados de procura
- 20 propuestas SMP-001 a SMP-020 con 3-6 materiales cada una (items en JSON)
- Proveedores: Materiales del Centro, Aceros Nacionales, Ferremundo, Construmarket, Proveedora Industrial, MetalMecánica, Eléctricos Global, Tuberías del Norte
- Proyectos target: PRJ-001, PRJ-002, PRJ-OVF-001, PRJ-OVF-003, PRJ-OVF-005, PRJ-OVF-008, PRJ-OVF-010, PRJ-OVF-013, PRJ-OVF-015, PRJ-OVF-018, PRJ-OVF-020, PRJ-OVF-025, PRJ-OVF-038, PRJ-OVF-040, PRJ-OVF-043, PRJ-OVF-044, PRJ-OVF-045, PRJ-OVF-046, PRJ-OVF-047, PRJ-OVF-048
- Plazos: 15-120 días con unidades variadas (días, semanas, meses)

**Archivos:**
- `_seed_supplier_proposals.php` [ELIMINADO] — script temporal
