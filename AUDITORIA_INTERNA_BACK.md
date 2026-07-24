# INFORME DE AUDITORÍA INTERNA — BACKEND LARAVEL (Infraestructura)

**Fecha:** 2026-07-24
**Auditor:** Senior Backend Auditor
**Alcance:** Backend completo (Laravel 9 + Sanctum + MySQL/MariaDB)
**Stack:** PHP 8.0+, Laravel 9, Sanctum 3, MySQL/MariaDB, OpenAI/Gemini/Anthropic APIs

---

## RESUMEN EJECUTIVO

| Metrica | Valor |
|---------|-------|
| **Lineas de codigo (app/)** | ~3,500 |
| **Endpoints API** | 59 |
| **Modelos Eloquent** | 14 |
| **Tests funcionales** | ~128 tests / 342 assertions |
| **Cobertura de tests** | **73% endpoints cubiertos** (mejorado desde <5%) |
| **Hallazgos totales** | 50+ (12 criticos, 15 altos, 20 medios, 10 bajos) |
| **Nuevos hallazgos hoy** | 5 criticos, 5 altos, 12 medios |
| **Estado general** | **Funcional, con mejoras recientes, pero aun con riesgos de seguridad significativos** |

---

## CRITICOS — Accion inmediata

### Previos (aun abiertos)

| ID | Hallazgo | Archivo | Riesgo | Accion |
|----|----------|---------|--------|--------|
| **S-01** | **GEMINI_API_KEY expuesta en `.env` commitado** | `.env` | Compromiso total de clave API Google | **ROTAR INMEDIATAMENTE** en Google Cloud Console. Eliminar del git history. |
| **S-05** | **APP_KEY expuesta en `.env` commitado** | `.env` | Desencriptacion de cookies/sesiones, firma de tokens | **ROTAR** (`php artisan key:generate --force`). Invalidar sesiones/tokens. |
| **S-03** | **APP_DEBUG=true en `.env`** | `.env` | Fuga de stack traces, variables de entorno, rutas en errores 500 | `APP_DEBUG=false` en produccion. |

### Nuevos (2026-07-24)

| ID | Hallazgo | Archivo | Riesgo | Accion |
|----|----------|---------|--------|--------|
| **C-02** | **ProjectObserver::updated() notifica a TODOS los usuarios en cada cambio de status** | `app/Observers/ProjectObserver.php:17` | `User::all()` + foreach notifica a usuarios inactivos, sin push tokens, roles irrelevantes. 100+ usuarios = 100+ llamadas HTTP a Expo Push API por cada update. Spam, DoS parcial. | Filtrar por `role` relevante + `status = Active` + verificar push token existente |
| **C-03** | **ProjectObserver::updated() bloquea el request HTTP (notificaciones sincronicas)** | `app/Observers/ProjectObserver.php:11-22` | Cada `$user->notify()` ejecuta `ExpoPushService::sendToUser()` con `Http::post()` sincronico. Si Expo esta lento, el request HTTP queda bloqueado. | Implementar `ShouldQueue` en la notificacion |
| **C-04** | **estimateCost() en AIEvaluationService tiene keys incorrectas (pricing siempre $0)** | `app/Services/AI/AIEvaluationService.php:222-241` | Keys del pricing son `chatgpt`/`claude` pero el provider pasado es `openai`/`anthropic`. Match nunca ocurre para OpenAI/Anthropic -> costos siempre $0. Solo Gemini funciona. | Cambiar keys del pricing a `openai`, `anthropic` |
| **C-05** | **config(["ai.{$key}" => $config]) muta estado global en runtime** | `app/Services/AI/AIEvaluationService.php:61` | Inyecta API keys al config global de Laravel en caliente. En entornos con workers persistentes (RoadRunner, Swoole, Octane), un request puede filtrar API key al siguiente request. | Pasar config directamente al constructor del provider, no via `config()` |
| **C-06** | **ExpoPushService no procesa respuesta de la API de Expo** | `app/Services/ExpoPushService.php:29-31` | Expo devuelve errores por token invalido (`DeviceNotRegistered`) pero se ignoran. Tokens zombies nunca se limpian y se reintentan en cada notificacion. | Validar respuesta JSON, eliminar tokens con error `DeviceNotRegistered` |
| **C-07** | **AiConfiguration::base_url en $fillable permite SSRF** | `app/Models/AiConfiguration.php` | `base_url` en fillable. Si atacante controla `base_url`, las llamadas API (con API key) se redirigen a servidor controlado -> exfiltracion de prompts/respuestas. | Mover `base_url` a `$guarded` o validar en controlador |
| **C-08** | **AiConfigurationService cachea API keys desencriptadas en texto plano** | `app/Services/AI/AiConfigurationService.php:66,81` | `Cache::forever()` almacena API keys desencriptadas. Driver `file` = disco, driver `redis` = memoria compartida. Cualquier proceso con acceso al cache lee keys planas. | Cifrar en cache o usar driver efimero |

---

## ALTOS — Prioridad alta (este sprint)

### Previos (aun abiertos)

| ID | Hallazgo | Archivo | Riesgo | Accion |
|----|----------|---------|--------|--------|
| **S-02** | **CORS: `supports_credentials=false` pero `allowed_origins` basado en env** | `config/cors.php` | Mitigado parcialmente. API bearer no necesita credentials. | Verificar en produccion |
| **S-07** | **Password policy debil (solo `min:8`)** | `AuthController.php:17`, `UserController.php:35` | Credenciales debiles, credential stuffing | Minimo 12 chars, 1 mayus, 1 minus, 1 numero, 1 simbolo |
| **A-02** | **GeminiProvider y AnthropicProvider extienden OpenAIProvider (herencia incorrecta)** | `GeminiProvider.php:8`, `AnthropicProvider.php:8` | Acoplamiento fuerte, metodos `private` inaccesibles, violacion LSP | Refactor a clase abstracta base `BaseAIProvider` |
| **A-03** | **Sin soft deletes en modelos criticos** | `Project`, `ProjectProposal`, `Contractor`, `SupplierInvitation` | Perdida irreversible de datos, sin auditoria de borrado | Anadir `SoftDeletes` + `deleted_at` |
| **A-04** | **Sin paginacion en listados** | `ProjectController::index()`, `SupportController::contractors()`, `auditLogs` | OOM / DoS en tablas grandes | `paginate(20)` + `LengthAwarePaginator` |
| **A-05** | **Sin API versioning** | `routes/api.php` | Breaking changes imposibles de gestionar | Prefijar rutas: `Route::prefix('v1')` |
| **A-06** | **Seeders sin roles asignados** | `DatabaseSeeder.php` | Usuarios de prueba sin permisos claros | Asignar roles en seeders |

### Nuevos (2026-07-24)

| ID | Hallazgo | Archivo | Riesgo | Accion |
|----|----------|---------|--------|--------|
| **A-07** | **ID generado con timestamp (`Hisv`) puede colisionar en alta concurrencia** | `ProjectController.php:153,232` | `'PROP-' . now()->format('Hisv')` resolucion 1/100s. 2+ requests mismo 0.01s -> PK duplicada -> 500. Mismo problema en `AuditLog::id` | Usar `Str::uuid()` o `Str::orderedUuid()` |
| **A-08** | **nextProjectId() y nextContractorCode() race condition** | `ProjectController.php:365-371`, `SupportController.php:252-262`, `ContractorController.php:127-137` | Read -> increment -> write sin lock. Dos requests simultaneos leen mismo `last` y generan mismo ID | Transaccion + `lockForUpdate()` o UUID |
| **A-09** | **SupplierInvitation::isValid() no tiene expiracion temporal** | `app/Models/SupplierInvitation.php` | Link de invitacion de meses/anos sigue valido si no se uso ni reemplazo | Agregar columna `expires_at` + verificar en `isValid()` |
| **A-10** | **AIEvaluationController validacion Rule::in sin array** | `AIEvaluationController.php:49` | `Rule::in('chatgpt', 'gemini', 'claude')` falta array wrapper. Laravel lo convierte internamente pero es incorrecto. | `Rule::in(['chatgpt', 'gemini', 'claude'])` |
| **A-11** | **PushToken sin unique constraint -> duplicados** | Migracion push_tokens | `user_id + token` sin unique. Pueden acumularse tokens duplicados con cada login. | Anadir unique constraint |
| **A-12** | **GeminiProvider expone API key en URL query param** | `GeminiProvider.php` | API key en URL: `?key={$apiKey}`. Queda expuesta en logs del servidor, proxies, referrer headers. | Usar header `x-goog-api-key` en lugar de query param |

---

## MEDIOS — Backlog proximo sprint

### Previos (aun abiertos)

| ID | Hallazgo | Archivo | Impacto |
|----|----------|---------|---------|
| **M-01** | Validacion inline en controllers (sin FormRequests) | Varios | Duplicacion, dificil testeo, inconsistencia |
| **M-02** | Sin eventos/observers para auditoria | `ProjectController::log()` | Acoplamiento |
| **M-03** | Sin jobs/queues para operaciones lentas | `AIEvaluationService::evaluate()` | Bloqueo HTTP |
| **M-04** | `Project::nextProjectId()` race condition | `ProjectController.php:365-371` | IDs duplicados |
| **M-05** | `AuditLog::id` generado con timestamp (colision) | `ProjectController.php:382` | PK duplicada |
| **M-06** | `Contractor::code` y `Project::id` generados manualmente | `SupportController`, `ProjectController` | Duplicados en concurrencia |
| **M-07** | `AiConfiguration::api_key` encriptada pero sin rotacion | Migracion ai_configurations | Claves expuestas si BD comprometida |
| **M-08** | Sin tests de integracion para flujos criticos | `tests/Feature/` | Regresiones silenciosas |
| **M-09** | `ProjectResource::whenLoaded('documents')` | `ProjectResource.php:54` | N+1 si no se hace `load()` |
| **M-11** | `config/ai.php` usa `env()` directamente | `config/ai.php` | Config no cacheable |

### Nuevos (2026-07-24)

| ID | Hallazgo | Archivo | Impacto |
|----|----------|---------|---------|
| **M-12** | `UserController` const global fuera de clase | `UserController.php:12` | Constantes `VALID_ROLES` definidas globalmente contaminan namespace |
| **M-13** | `ContractorController` const global fuera de clase | `ContractorController.php:10` | Mismo problema que M-12 |
| **M-14** | Rate limiter public-api devuelve 429 sin JSON amigable | `RouteServiceProvider.php` | Cliente recibe HTML plano en 429 |
| **M-15** | Varios endpoints eliminan ThrottleRequests middleware | `routes/api.php:41,43,44,45,80` | `withoutMiddleware([ThrottleRequests::class])` deja rutas auth sin rate limiting |
| **M-16** | `UserController::sendResetLink()` no verifica email valido | `UserController.php:100-108` | Envia reset link incluso a usuarios sin email configurado |
| **M-17** | `ProjectPayment` sin `updated_at` | Migracion payments | Tabla no tiene timestamps completo |
| **M-18** | `ProjectController::STATUSES` definido pero NUNCA usado | `ProjectController.php:20-30` | Codigo muerto |
| **M-19** | `sanitizeInput()` trunca a 2000 chars sin warning | `OpenAIProvider.php` | Datos validos de proyectos largos se pierden silenciosamente |
| **M-20** | Rate limiter `api` usa 60/min global | `RouteServiceProvider.php:48-49` | Mismo limite para todos los endpoints autenticados, sin diferenciar operaciones pesadas |
| **M-21** | `AnthropicProvider` hardcodea version `2023-06-01` | `AnthropicProvider.php` | Anthropic depreca versiones viejas. Cuando venza, llamadas fallan via failover silenciosamente |

---

## BAJOS — Mejora continua

### Previos (aun abiertos)

| ID | Hallazgo | Archivo |
|----|----------|---------|
| **L-01** | Constantes globales en controllers | `ContractorController.php:10`, `UserController.php:12-17` |
| **L-02** | `ProjectController::STATUSES` array duplicado vs migracion | `ProjectController.php:20` |
| **L-03** | `strip_tags()` repetido en 4 controllers | Support, Contractor, Material, User |
| **L-04** | `sanitizeFilename()` duplica logica de `Str::slug()` | `ProjectDocumentController.php:97-125` |
| **L-05** | `AiConfigController::test*()` duplican logica de providers | `AiConfigController.php:267-352` |
| **L-06** | Sin phpstan/psalm/larastan en CI | `composer.json` |
| **L-07** | Sin pint config para code style | `composer.json` |
| **L-08** | `AuditLog::role` enum no incluye `CATALOGOS`, `SISTEMA` | Migracion audit_logs |
| **L-10** | `syncProjectCounts()` hace 2 queries separadas | `ProjectDocumentController.php:146-152` |

### Nuevos (2026-07-24)

| ID | Hallazgo | Archivo |
|----|----------|---------|
| **L-11** | `optional()` usado en valores que nunca seran null | Multiples |
| **L-12** | `SupplierInvitation::UPDATED_AT = null` sin comentario explicativo | `SupplierInvitation.php` |
| **L-13** | `formatDocument()`, `formatProposal()` duplican logica de serializacion | `ProjectDocumentController.php`, `SupportController.php` |
| **L-14** | README.md es boilerplate de Laravel, no describe la app real | `README.md` |
| **L-15** | `composer.json` requiere `doctrine/dbal:*` (cualquier version) | `composer.json:9` |

---

## ANALISIS DE SEGURIDAD PROFUNDO (actualizado)

### Autenticacion y Autorizacion

| Aspecto | Estado | Hallazgo |
|---------|--------|----------|
| **Sanctum token expiration** | Implementado | 24h config + refresh automatico 60min antes + grace period 60s |
| **Token rotation** | Implementado | Nuevo token en header `X-Refresh-Token`, preserva name/abilities |
| **Max 2 sesiones por usuario** | Implementado | `AuthController::login()` |
| **Logout revoca token** | Implementado | `AuthController::logout()` |
| **User status check (Active/Inactive)** | Implementado | `AuthController::login()`, `User::isInactive()` |
| **Role middleware** | Implementado | `CheckRole.php` aplicado a todas las rutas criticas (fix A-01) |
| **Rate limiting login** | Implementado | `throttle:public-api` = 10/min por IP |
| **Password policy** | Debil | Solo `min:8` |
| **2FA / MFA** | Ausente | No implementado |

### Validacion de Entrada y Sanitizacion

| Vector | Estado | Detalle |
|--------|--------|---------|
| **SQL Injection** | Protegido | Eloquent + parameter binding en todo el codigo |
| **XSS (stored)** | Parcial | `strip_tags()` en campos de texto pero no en `description` de propuestas |
| **Path Traversal (upload)** | Protegido | `sanitizeFilename()` usa `basename()`, quita null bytes, normaliza UTF-8 |
| **MIME Type Validation** | En docs | `StoreProjectDocumentRequest` usa `finfo` server-side + extensiones permitidas |
| **Prompt Injection (IA)** | 3 capas | System prompt reforzado + `sanitizeInput()` + validacion `max:2000` |
| **File Size Limit** | 50MB | `StoreProjectDocumentRequest` |
| **File Type Confusion** | Manejado | `application/octet-stream` solo para `.dwg/.dxf`; `application/zip` para `.xlsx/.ods` |
| **SSRF via AiConfiguration** | **VULNERABLE** | `base_url` en fillable sin validacion |
| **API keys en cache plano** | **VULNERABLE** | `Cache::forever()` almacena API keys desencriptadas |

### Configuracion y Secrets

| Item | Estado | Riesgo |
|------|--------|--------|
| **APP_KEY en git** | **CRITICO** | Rotar ya |
| **GEMINI_API_KEY en git** | **CRITICO** | Rotar ya |
| **API keys IA en cache plano** | **ALTO** | `AiConfigurationService` cachea keys desencriptadas |
| **Gemini API key en URL** | **ALTO** | Query param en vez de header |
| **DB_PASSWORD vacio en .env** | Dev only | OK si solo local |
| **Sanctum stateful domains** | Configurado | `localhost:3000` etc. |
| **CSP Headers** | Implementado | `AddCspHeaders.php` middleware en grupo `api` |
| **HTTPS enforcement** | No forzado | `TrustProxies` configurado pero no `ForceHttps` |

---

## COBERTURA DE TESTS (actualizada)

```
tests/
Feature/
  AuthTest.php
  ContractorMaterialTest.php
  ExampleTest.php
  InvitationLinkTest.php
  ProjectLifecycleTest.php
  RoleMiddlewareTest.php
  SupplierInvitationTest.php
  TokenExpirationIntegrationTest.php
  TokenExpirationTest.php
  UserManagementTest.php
Unit/
  ExampleTest.php
```

**Total: ~128 tests / 342 assertions**

### Cobertura por modulo

| Modulo | Endpoints | Cubiertos | % |
|--------|-----------|-----------|---|
| Auth (login/logout/me) | 4 | 4 | 100% |
| Users (CRUD + toggle + reset) | 6 | 6 | 100% |
| Contractors (config CRUD + public) | 8 | 8 | 100% |
| Materials (config CRUD + public) | 6 | 6 | 100% |
| Projects (CRUD + lifecycle) | 14 | 14 | 100% |
| Invitations + Proposals | 5 | 5 | 100% |
| **Push Tokens** | **2** | **0** | **0%** |
| **Audit Logs** | **1** | **0** | **0%** |
| **Project Documents** | **4** | **0** | **0%** |
| **AI Config** | **8** | **0** | **0%** |
| **AI Evaluation** | **1** | **0*** | **0%** |
| **TOTAL** | **59** | **43** | **73%** |

*\*Solo middleware, no logica funcional de IA.*

---

## ARQUITECTURA Y PATRONES

### Fortalezas

1. **Service Layer para IA** — `AIEvaluationService` orquesta providers con failover limpio
2. **Interface `AIProviderInterface`** — Contrato claro, extensible
3. **FormRequest para upload** — Validacion centralizada y testeable (`StoreProjectDocumentRequest`)
4. **Middleware `refresh.token`** — Separacion de concerns, no acopla logica de negocio
5. **Audit Log inmutable** — `UPDATED_AT = null`, snapshot de datos criticos
6. **Sanitizacion de filenames** — Defensa en profundidad (basename, null bytes, UTF-8, whitelist chars)
7. **Mejora sustancial en tests** — De <5% a 73% cobertura de endpoints

### Debilidades Arquitectonicas

1. **Fat Controllers** — `ProjectController` (393 lineas), `SupportController` (275 lineas) concentran logica
2. **Sin Domain Events/Observers** — Auditoria acoplada a controllers
3. **Sin Jobs/Queues** — IA evaluation, push notifications, uploads bloquean HTTP
4. **Herencia rota en Providers IA** — `GeminiProvider extends OpenAIProvider` viola LSP
5. **Observers sincronicos** — `ProjectObserver` envia push notifications bloqueantes
6. **IDs manuales** — Generacion con race conditions y colision potencial
7. **API keys en cache sin cifrado** — Riesgo de exposicion
8. **SSRF vector** — `AiConfiguration::base_url` sin restriccion

---

## VECTORES DE ATAQUE ABIERTOS (Priorizados)

1. **Exfiltracion de API keys IA via SSRF** — `AiConfiguration.base_url` permite redirigir llamadas con API key a servidor atacante
2. **Fuga de API keys via cache** — `AiConfigurationService` cachea API keys desencriptadas en disco
3. **Fuga de API key via URL** — Gemini pone API key en query param de URL
4. **DoS por notificaciones sincronicas** — `ProjectObserver` bloquea HTTP request llamando a Expo
5. **Colision de IDs en alta concurrencia** — IDs con timestamp `Hisv` sin UUID
6. **Tokens push zombies** — `ExpoPushService` ignora errores de tokens invalidos
7. **Invitaciones de proveedores sin expiracion** — Links validos por tiempo indefinido
8. **Costos de IA incorrectos** — `estimateCost()` con keys incorrectas da $0 siempre

---

## PLAN DE ACCION PRIORIZADO

### Fase 0 — HOY (Criticos nuevos + previos abiertos)

1. [ ] **ROTAR GEMINI_API_KEY** en Google Cloud Console
2. [ ] **ROTAR APP_KEY** (`php artisan key:generate --force`)
3. [ ] **Limpiar git history** (BFG Repo-Cleaner o `git filter-repo`)
4. [ ] Filtrar destinatarios en `ProjectObserver` + verificar push tokens
5. [ ] Mover notificaciones push a `ShouldQueue`
6. [ ] Corregir `estimateCost()` keys (`chatgpt`->`openai`, `claude`->`anthropic`)
7. [ ] Reemplazar IDs con timestamp por `Str::uuid()` en `ProjectProposal` y `AuditLog`

### Fase 1 — ESTA SEMANA (Altos)

8. [ ] `APP_DEBUG=false` en produccion
9. [ ] Password policy: `Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()`
10. [ ] Refactor `GeminiProvider` / `AnthropicProvider` -> `BaseAIProvider` abstracta
11. [ ] Pasar config AI directamente a providers, no via `config()` global
12. [ ] Validar respuesta de Expo API y limpiar tokens invalidos
13. [ ] Agregar `expires_at` a `SupplierInvitation`
14. [ ] Fix `Rule::in()` en `AIEvaluationController`
15. [ ] Migrar API key de Gemini a header en vez de query param
16. [ ] Agregar `unique(user_id, token)` en push_tokens

### Fase 2 — PROXIMO SPRINT (Medios)

17. [ ] Migrar validaciones inline -> FormRequests
18. [ ] Implementar `SoftDeletes` en modelos criticos
19. [ ] Paginacion en todos los `index()`
20. [ ] API versioning: `Route::prefix('v1')`
21. [ ] Fix race conditions en generadores de ID
22. [ ] Tests para Push Tokens, Documents, AI Config, AI Evaluation
23. [ ] Rate limiting diferenciado por operacion
24. [ ] Sin tests de cobertura real de logica IA

### Fase 3 — MEJORA CONTINUA (Bajos)

25. [ ] Constantes centralizadas (`config/roles.php`, `config/project_statuses.php`)
26. [ ] Events/Observers para auditoria desacoplada
27. [ ] Jobs/Queues para operaciones pesadas
28. [ ] PHPStan/Larastan nivel 5+ en CI
29. [ ] Laravel Pint configurado

---

## CONCLUSION

El backend ha **mejorado significativamente** desde la auditoria anterior:
- Middleware `role` aplicado a todas las rutas criticas
- Rate limiting funcional para endpoints publicos
- MIME validation real en upload de documentos
- Tests incrementados de 19 a ~128 (73% cobertura de endpoints)

**Pero persisten y se agregan riesgos importantes:**

1. **2 secretos en git history** (APP_KEY, GEMINI_API_KEY) - rotacion inmediata
2. **ProjectObserver bloqueante** que notifica a todos los usuarios sincronicamente
3. **Costos de IA erroneos** ($0 siempre) desde implementacion inicial
4. **API keys en cache plano** - exposicion silenciosa
5. **SSRF via AiConfiguration** - permite exfiltrar API keys
6. **Colision de IDs** en alta concurrencia
7. **Tokens push zombies** acumulandose sin limpieza

**Recomendacion:** Abordar Fase 0 esta semana. Continuar con Fase 1 y 2 en el proximo sprint antes de agregar nuevas features.
