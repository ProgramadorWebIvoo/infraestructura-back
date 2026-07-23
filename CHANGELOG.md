# CHANGELOG

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
