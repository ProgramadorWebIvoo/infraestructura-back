# 📋 INFORME DE AUDITORÍA INTERNA — BACKEND LARAVEL (Infraestructura)

**Fecha:** 2026-07-23
**Auditor:** Senior Backend Auditor
**Alcance:** Backend completo (Laravel 9 + Sanctum + MySQL/MariaDB)
**Stack:** PHP 8.0+, Laravel 9, Sanctum 3, MySQL/MariaDB, OpenAI/Gemini/Anthropic APIs

---

## 🎯 RESUMEN EJECUTIVO

| Métrica | Valor |
|---------|-------|
| **Líneas de código (app/)** | ~3,500 |
| **Endpoints API** | 45+ |
| **Modelos Eloquent** | 13 |
| **Tests funcionales** | 24 (solo tokens + 2 boilerplate) |
| **Cobertura de tests** | **< 5%** (crítico) |
| **Hallazgos previos (CHANGELOG)** | 31 (5 🔴 Críticos, 7 🟠 Altos, 11 🟡 Medios, 8 🟢 Bajos) |
| **Estado general** | **Funcional pero con deuda técnica y riesgos de seguridad significativos** |

---

## 🔴 CRÍTICOS (Acción inmediata requerida)

| ID | Hallazgo | Archivo/Evidencia | Riesgo | Acción |
|----|----------|-------------------|--------|--------|
| **S-01** | **GEMINI_API_KEY expuesta en `.env` commitado** | `.env` línea 61 | Compromiso total de clave API Google | **ROTAR INMEDIATAMENTE** en Google Cloud Console. Eliminar del git history (`git filter-repo` o BFG). |
| **S-05** | **APP_KEY expuesta en `.env` commitado** | `.env` línea 3 | Desencriptación de cookies/sesiones, firma de tokens | **ROTAR** (`php artisan key:generate --force`). Invalidar todas las sesiones/tokens. |
| **BD-01** | **Type mismatch FK `project_documents.project_id` (20 vs 40 chars)** | Migración `2026_07_01_200000_create_project_documents_table.php` línea 13 vs `projects.id` (40) | FK falla silenciosamente o error en BD | Cambiar `string('project_id', 20)` → `string('project_id', 40)` + nueva migración. |
| **A-01** | **Rutas sensibles sin middleware de rol** | `routes/api.php` líneas 45-55, 60-66 | Escalada de privilegios (cualquier rol autenticado puede `approve-investment`, `pay`, `select-contractor`, `reject-proposals`) | Aplicar `middleware('role:PROCURA,ADMIN,SUPERADMIN')` según matriz de permisos. |
| **C-01** | **Typo `attempLog` en `AIEvaluationService`** | `app/Services/AI/AIEvaluationService.php` línea 148 (`$this->attempLog` vs `$this->attemptLog`) | `attemptLog` siempre vacío en modo forzado; logs de diagnóstico rotos | Corregir typo. |

---

## 🟠 ALTOS (Prioridad alta - Sprint actual)

| ID | Hallazgo | Archivo | Riesgo | Acción |
|----|----------|---------|--------|--------|
| **S-02** | **CORS permite cualquier origen (`*`)** | `config/cors.php` línea 22: `allowed_origins => [env('FRONTEND_URL')]` pero `allowed_origins_patterns => []` y `supports_credentials => false` | CSRF, data exfiltration si frontend comprometido | Restringir a dominios exactos. Habilitar `supports_credentials` solo si usa cookies. |
| **S-03** | **APP_DEBUG=true en `.env`** | `.env` línea 4 | Fuga de stack traces, variables de entorno, rutas en errores 500 | `APP_DEBUG=false` en producción. |
| **S-04** | **Sin rate limiting en endpoints públicos** | `routes/api.php` líneas 26-29: solo `throttle:public-api` (no definido en `Kernel.php`) | Fuerza bruta login, spam invitaciones, DoS | Definir `throttle:public-api` en `Kernel.php` (ej. `60,1` = 60/min). Aplicar a `/login`, `/contractors`, `/public/*`. |
| **S-06** | **Validación MIME real solo en `StoreProjectDocumentRequest`** | Otros uploads (si existen) no validan MIME server-side | Subida de archivos maliciosos (webshells, polyglots) | Aplicar patrón `finfo` + extensión a **todo** upload. |
| **S-07** | **Password policy débil (solo `min:8`)** | `AuthController.php` línea 17, `UserController.php` línea 35 | Credenciales débiles, credential stuffing | Mínimo 12 chars, 1 mayús, 1 minús, 1 número, 1 símbolo. Usar `Password::defaults()` en `AppServiceProvider`. |
| **A-02** | **GeminiProvider y AnthropicProvider extienden OpenAIProvider (herencia incorrecta)** | `GeminiProvider.php` línea 8, `AnthropicProvider.php` línea 8 | Acoplamiento fuerte, métodos `private` inaccesibles, violación LSP | Refactor a **clase abstracta base** `BaseAIProvider` + composición. |
| **A-03** | **Sin soft deletes en modelos críticos** | `Project`, `ProjectProposal`, `Contractor`, `SupplierInvitation` | Pérdida irreversible de datos, sin auditoría de borrado | Añadir `SoftDeletes` + `deleted_at` en migraciones. |
| **A-04** | **Sin paginación en listados** | `ProjectController::index()`, `SupportController::contractors()`, `AuditLog::latest()->limit(200)` | OOM / DoS en tablas grandes | `paginate(20)` + `LengthAwarePaginator` en Resources. |
| **A-05** | **Sin API versioning** | `routes/api.php` sin prefijo `/v1/` | Breaking changes imposibles de gestionar | Prefijar rutas: `Route::prefix('v1')->group(...)`. |
| **A-06** | **Seeders sin roles asignados** | `DatabaseSeeder.php` (no leído pero CHANGELOG lo menciona) | Usuarios de prueba sin permisos claros | Asignar roles en seeders. |

---

## 🟡 MEDIOS (Backlog próximo sprint)

| ID | Hallazgo | Archivo | Impacto |
|----|----------|---------|---------|
| **M-01** | **Validación inline en controllers (sin FormRequests)** | `ProjectController`, `AuthController`, `ContractorController`, `MaterialController`, `SupportController`, `AiConfigController` | Duplicación, difícil testeo, inconsistencia |
| **M-02** | **Sin eventos/observers para auditoría** | `ProjectController::log()`, `ProjectDocumentController::log()` | Acoplamiento, no se audita cambios por otros medios (tinker, jobs, seeds) |
| **M-03** | **Sin jobs/queues para operaciones lentas** | `AIEvaluationService::evaluate()` (60s timeout), envío emails, uploads | Bloqueo de worker HTTP, timeouts en frontend |
| **M-04** | **`Project::nextProjectId()` race condition** | `ProjectController.php` línea 365-371 | IDs duplicados bajo concurrencia | Usar `DB::transaction` + `lockForUpdate()` o UUID/ULID. |
| **M-05** | **`AuditLog::id` generado con timestamp (colisión posible)** | `ProjectController.php` línea 382, `ProjectDocumentController.php` línea 171 | PK duplicada bajo alta concurrencia | Usar `Str::uuid()` o `ULID`. |
| **M-06** | **`Contractor::code` y `Project::id` generados manualmente (race condition)** | `SupportController::nextContractorCode()`, `ProjectController::nextProjectId()` | Duplicados en concurrencia | Secuencia en BD o UUID. |
| **M-07** | **`AiConfiguration::api_key` encriptada pero sin rotación automática** | Migración `2026_07_22_000002_create_ai_configurations_table.php` línea 15 | Claves antiguas expuestas si BD comprometida | Implementar rotación periódica + versionado de claves. |
| **M-08** | **Sin tests de integración para flujos críticos** | `tests/Feature/` solo 3 archivos | Regresiones silenciosas en flujos de negocio | Tests para: flujo proyecto completo, invitaciones, upload docs, evaluación IA. |
| **M-09** | **`ProjectResource` expone `documents` solo con `whenLoaded`** | `ProjectResource.php` línea 54 | N+1 si no se hace `load('documents')` | Documentar o usar `whenLoaded` consistentemente. |
| **M-10** | **`AiEvaluationService::evaluateWithProvider()` tiene código muerto/duplicado** | Líneas 126-169: `catch` usa variables no definidas (`$provider`, `$forcedprovider`, `$startTime`) | Bugs en modo forzado | Refactor completo del método. |
| **M-11** | **`config/ai.php` usa `env()` directamente en config (anti-patrón)** | `config/ai.php` líneas 36, 49, 62 | Config no cacheable (`php artisan config:cache` falla) | Mover `env()` a `AppServiceProvider` o usar `config()` solo. |

---

## 🟢 BAJOS (Mejora continua)

| ID | Hallazgo | Archivo |
|----|----------|---------|
| **L-01** | Constantes `CONTRACTOR_STATUSES`, `VALID_ROLES`, `VALID_STATUSES` definidas como `const` global en controllers | `ContractorController.php` línea 10, `UserController.php` líneas 12-17 |
| **L-02** | `ProjectController::STATUSES` array duplicado vs migración enum | `ProjectController.php` línea 20 |
| **L-03** | `strip_tags()` repetido en 4 controllers (XSS básico pero no completo) | `SupportController`, `ContractorController`, `MaterialController`, `UserController` |
| **L-04** | `ProjectDocumentController::sanitizeFilename()` duplica lógica que podría ser `Str::slug()` + timestamp | `ProjectDocumentController.php` líneas 97-125 |
| **L-05** | `AiConfigController::test*()` métodos duplican lógica de providers | `AiConfigController.php` líneas 267-352 |
| **L-06** | Sin `phpstan`/`psalm`/`larastan` en CI | `composer.json` |
| **L-07** | Sin `pint`/`pint` config para code style | `composer.json` tiene `laravel/pint` pero no configurado |
| **L-08** | `AuditLog::role` enum no incluye `CATALOGOS`, `SISTEMA` (usados en código) | Migración `2026_06_30_000009_create_audit_logs_table.php` |
| **L-09** | `SupplierInvitation::isValid()` no verifica expiración por tiempo | `SupplierInvitation.php` línea 34-37 |
| **L-10** | `ProjectDocumentController::syncProjectCounts()` hace 2 queries separadas | `ProjectDocumentController.php` líneas 146-152 |

---

## 🔐 ANÁLISIS DE SEGURIDAD PROFUNDO

### Autenticación y Autorización
| Aspecto | Estado | Hallazgo |
|---------|--------|----------|
| **Sanctum token expiration** | ✅ Implementado | 24h config + refresh automático 60min antes + grace period 60s |
| **Token rotation** | ✅ Implementado | Nuevo token en header `X-Refresh-Token`, preserva name/abilities |
| **Max 2 sesiones por usuario** | ✅ Implementado | `AuthController::login()` línea 36-38 |
| **Logout revoca token** | ✅ Implementado | `AuthController::logout()` línea 71 |
| **User status check (Active/Inactive)** | ✅ Implementado | `AuthController::login()` línea 29-33, `User::isInactive()` |
| **Role middleware** | ✅ Implementado | `CheckRole.php` pero **no aplicado a rutas críticas** (A-01) |
| **Rate limiting login** | ⚠️ Parcial | Middleware `throttle:public-api` referenciado pero **no definido** en `Kernel.php` |
| **Password policy** | ❌ Débil | Solo `min:8` (S-07) |
| **2FA / MFA** | ❌ Ausente | No implementado |

### Validación de Entrada y Sanitización
| Vector | Estado | Detalle |
|--------|--------|---------|
| **SQL Injection** | ✅ Protegido | Eloquent + parameter binding en todo el código |
| **XSS (stored)** | ⚠️ Parcial | `strip_tags()` en campos de texto pero **no en `description` de propuestas** (se envía a IA) |
| **Path Traversal (upload)** | ✅ Protegido | `sanitizeFilename()` usa `basename()`, quita null bytes, normaliza UTF-8 |
| **MIME Type Validation** | ✅ En docs | `StoreProjectDocumentRequest` usa `finfo` server-side + extensiones permitidas |
| **Prompt Injection (IA)** | ✅ 3 capas | System prompt reforzado + `sanitizeInput()` (patrones + delimitadores `[INICIO_DATOS]`) + validación `max:2000` |
| **File Size Limit** | ✅ 50MB | `StoreProjectDocumentRequest` línea 45 |
| **File Type Confusion** | ✅ Manejado | Caso especial `application/octet-stream` solo para `.dwg/.dxf`; `application/zip` para `.xlsx/.ods` |

### Configuración y Secrets
| Item | Estado | Riesgo |
|------|--------|--------|
| **APP_KEY en git** | ❌ CRÍTICO | Rotar ya (S-05) |
| **GEMINI_API_KEY en git** | ❌ CRÍTICO | Rotar ya (S-01) |
| **DB_PASSWORD vacío en .env** | ⚠️ Dev only | OK si solo local, pero `.env` no debería commitearse |
| **Sanctum stateful domains** | ✅ Configurado | `localhost:3000` etc. |
| **CSP Headers** | ✅ Implementado | `AddCspHeaders.php` middleware en grupo `api` |
| **HTTPS enforcement** | ❌ No forzado | `TrustProxies` configurado pero no `ForceHttps` middleware |

### Base de Datos
| Tabla | PK | FK Issues | Índices | Soft Deletes |
|-------|----|-----------|---------|--------------|
| `projects` | `id` (40) | ✅ | 6 índices | ❌ |
| `project_proposals` | `id` (40) | ✅ | 2 índices | ❌ |
| `project_documents` | `id` (bigint) | ❌ **project_id (20) vs projects.id (40)** | - | ❌ |
| `contractors` | `code` (30) | ✅ | - | ❌ |
| `personal_access_tokens` | `id` (bigint) | ✅ morphs | - | N/A |
| `audit_logs` | `id` (40) | ✅ | - | N/A (append-only) |
| `ai_configurations` | `id` (bigint) | - | unique(provider,model) | ❌ |
| `supplier_invitations` | `id` (uuid) | ✅ | - | ❌ (usa `used_at`/`replaced_by`) |

---

## 🧪 COBERTURA DE TESTS — CRÍTICO

```
tests/
├── Feature/
│   ├── TokenExpirationTest.php           (6 tests)
│   ├── TokenExpirationIntegrationTest.php (9 tests)
│   └── ExampleTest.php                    (1 test boilerplate)
└── Unit/
    └── ExampleTest.php                    (1 test boilerplate)
```

**Total: 17 tests reales + 2 boilerplate = 19 tests**

**Cobertura funcional: ~0%**
- ❌ 0 tests para: Proyectos (CRUD, estados, propuestas, pagos, documentos)
- ❌ 0 tests para: Contratistas, Materiales, Usuarios, Roles
- ❌ 0 tests para: Invitaciones proveedores, propuestas materiales
- ❌ 0 tests para: Evaluación IA (éxito, failover, proveedor forzado, prompt injection)
- ❌ 0 tests para: Auditoría, Configuración IA, Health checks

**Riesgo:** Cualquier refactor o fix rompe funcionalidad sin detectarse.

---

## 🏗️ ARQUITECTURA Y PATRONES

### Fortalezas
1. **Service Layer para IA** — `AIEvaluationService` orquesta providers con failover limpio
2. **Interface `AIProviderInterface`** — Contrato claro, extensible
3. **FormRequest para upload** — Validación centralizada y testeable (`StoreProjectDocumentRequest`)
4. **Middleware `refresh.token`** — Separación de concerns, no acopla lógica de negocio
5. **Audit Log inmutable** — `UPDATED_AT = null`, snapshot de datos críticos
6. **Sanitización de filenames** — Defensa en profundidad (basename, null bytes, UTF-8, whitelist chars)

### Debilidades Arquitectónicas
1. **Fat Controllers** — `ProjectController` (393 líneas), `SupportController` (275 líneas) concentran lógica de negocio
2. **Sin Domain Events/Observers** — Auditoría acoplada a controllers
3. **Sin Jobs/Queues** — IA evaluation (60s), emails, uploads bloquean HTTP
4. **Herencia rota en Providers IA** — `GeminiProvider extends OpenAIProvider` viola LSP
5. **Config no cacheable** — `config/ai.php` usa `env()` directamente
6. **IDs manuales con race conditions** — `nextProjectId()`, `nextContractorCode()`, `nextProposalId()`

---

## 📊 MATRIZ DE PERMISOS REQUERIDA (Para fix A-01)

| Endpoint | Método | Roles Permitidos | Middleware Actual | Requerido |
|----------|--------|------------------|-------------------|-----------|
| `/projects/{project}/review` | POST | `CIERRE_DE_OBRA`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:CIERRE_DE_OBRA,ADMIN,SUPERADMIN` |
| `/projects/{project}/approve-investment` | POST | `PROCURA`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:PROCURA,ADMIN,SUPERADMIN` |
| `/projects/{project}/proposals` | POST | `ANALISTA`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:ANALISTA,ADMIN,SUPERADMIN` |
| `/projects/{project}/proposals/{proposal}` | DELETE | `ANALISTA`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:ANALISTA,ADMIN,SUPERADMIN` |
| `/projects/{project}/submit-comparative` | POST | `ANALISTA`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:ANALISTA,ADMIN,SUPERADMIN` |
| `/projects/{project}/import-supplier-proposals` | POST | `ANALISTA`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:ANALISTA,ADMIN,SUPERADMIN` |
| `/projects/{project}/reject-proposals` | POST | `PROCURA`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:PROCURA,ADMIN,SUPERADMIN` |
| `/projects/{project}/select-contractor` | POST | `PROCURA`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:PROCURA,ADMIN,SUPERADMIN` |
| `/projects/{project}/payments` | POST | `FINANZAS`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:FINANZAS,ADMIN,SUPERADMIN` |
| `/projects/{project}/report-finished` | POST | `CIERRE_DE_OBRA`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:CIERRE_DE_OBRA,ADMIN,SUPERADMIN` |
| `/projects/{project}/verify-completion` | POST | `CIERRE_DE_OBRA`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:CIERRE_DE_OBRA,ADMIN,SUPERADMIN` |
| `/ai/evaluate-proposals` | POST | `PROCURA`, `ADMIN`, `SUPERADMIN` | `auth:sanctum`, `refresh.token` | + `role:PROCURA,ADMIN,SUPERADMIN` |
| `/users/*` | * | `SUPERADMIN`, `ADMIN` | `role:SUPERADMIN,ADMIN` | ✅ OK |
| `/contractors/config/*` | * | `SUPERADMIN`, `ADMIN` | `role:SUPERADMIN,ADMIN` | ✅ OK |
| `/materials/config/*` | * | `SUPERADMIN`, `ADMIN` | `role:SUPERADMIN,ADMIN` | ✅ OK |
| `/ai/config/*` | * | `SUPERADMIN`, `ADMIN` | `role:SUPERADMIN,ADMIN` | ✅ OK |

---

## 🚀 PLAN DE ACCIÓN PRIORIZADO

### **Fase 0 — HOY (Críticos)**
1. [ ] **ROTAR GEMINI_API_KEY** en Google Cloud Console
2. [ ] **ROTAR APP_KEY** (`php artisan key:generate --force`)
3. [ ] **Limpiar git history** (BFG Repo-Cleaner o `git filter-repo`)
4. [ ] **Fix FK `project_documents.project_id`** (migración: 20 → 40 chars)
5. [ ] **Aplicar middleware `role` a 12 rutas críticas** (matriz arriba)

### **Fase 1 — ESTA SEMANA (Altos)**
6. [ ] Definir `throttle:public-api` en `Kernel.php` (ej. `RateLimiter::for('public-api', ...)`)
7. [ ] `APP_DEBUG=false` en producción
8. [ ] CORS: restringir `allowed_origins` a dominios exactos
9. [ ] Password policy: `Password::min(12)->mixedCase()->numbers()->symbols()->uncompromised()`
10. [ ] Refactor `GeminiProvider` / `AnthropicProvider` → `BaseAIProvider` abstracta
11. [ ] Fix typo `attempLog` → `attemptLog` en `AIEvaluationService`

### **Fase 2 — PRÓXIMO SPRINT (Medios)**
12. [ ] Migrar validaciones inline → FormRequests (mínimo 8 nuevos)
13. [ ] Implementar `SoftDeletes` en `Project`, `ProjectProposal`, `Contractor`, `SupplierInvitation`
14. [ ] Paginación en todos los `index()` (`paginate(20)`)
15. [ ] API versioning: `Route::prefix('v1')`
16. [ ] Fix race conditions en generadores de ID (transacciones + lock o UUID)
17. [ ] `config/ai.php`: mover `env()` a `AppServiceProvider::boot()`
18. [ ] Test suite base: 50+ tests cubriendo flujos críticos

### **Fase 3 — MEJORA CONTINUA (Bajos)**
19. [ ] Constantes centralizadas (`config/roles.php`, `config/project_statuses.php`)
20. [ ] Events/Observers para auditoría desacoplada
21. [ ] Jobs/Queues para IA evaluation, emails, uploads pesados
22. [ ] PHPStan/Larastan nivel 5+ en CI
23. [ ] Laravel Pint configurado
24. [ ] Rotación automática de API keys IA

---

## 📝 CONCLUSIÓN

El backend **funciona** y tiene **buenas decisiones de seguridad recientes** (token rotation, file upload validation, prompt injection defense, CSP headers). Sin embargo:

1. **Dos secretos críticos en git history** (APP_KEY, GEMINI_API_KEY) requieren rotación **inmediata**.
2. **Autorización rota en 12 endpoints sensibles** — cualquier usuario autenticado puede aprobar inversiones, pagar, adjudicar contratos.
3. **FK rota en `project_documents`** — integridad referencial comprometida.
4. **Cobertura de tests inexistente** — refactorizar es de alto riesgo.
5. **Deuda técnica acumulada** en controllers, herencia de providers, race conditions, config no cacheable.

**Recomendación:** Frenar features nuevas. Dedicar 1-2 sprints a **estabilizar, securizar y testear** antes de seguir creciendo.