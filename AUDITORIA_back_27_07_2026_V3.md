# AUDITORIA_back_27_07_2026_V3

**Fecha:** 27 de julio de 2026
**Versión del Reporte:** V3
**Tipo de Auditoría:** Integral (Seguridad, Clean Code/SOLID, Rendimiento, Testing, Reglas de Negocio)
**Framework:** Laravel 9.x (PHP 8.0+)
**Repositorio:** `infraestructura-back` (rama `FIXES`)
**Alcance:** Código commiteado hasta `e167ee7` ("FIXES - Audit fixes M-08 & B-01 to B-06") + verificación línea por línea del diff **sin commitear** que migra la autenticación de `localStorage` a cookie `httpOnly` (`AuthController`, `Kernel`, `RefreshSanctumToken`, `AiConfiguration`, `config/cors.php`, `routes/api.php`, y los nuevos `TokenFromCookie`, `CorsDiagnosticController`, `DiagnoseCookieAuth`, `CookieAuthFlowTest`).

---

## 1. Resumen Ejecutivo

| Severidad | Cantidad | Notas |
|---|---|---|
| 🔴 Crítico | 3 | 2 nuevos, introducidos por el cambio sin commitear de cookie auth; 1 dato sensible (PII real) expuesto en dump SQL versionado |
| 🟠 Alto | 8 | 2 confirmados sin resolver desde auditorías previas, 1 residual de un fix parcial, 5 nuevos o reabiertos |
| 🟡 Medio | 9 | deuda técnica previa sin resolver + hallazgos nuevos |
| 🔵 Bajo | 6 | nomenclatura, código muerto, configuración inerte |
| **Total** | **26** | |

El equipo cerró de forma **verificable** casi todos los hallazgos M-01 a M-09 y B-01 a B-06 de la auditoría V2 (24/07/2026): constantes de clase, paginación, DTOs de evaluación IA, `BaseAIProvider` correctamente abstracta, inyección de dependencias explícita, `estimateCost()` corregido, notificaciones en cola, limpieza de tokens Expo muertos, y eliminación de API keys en claro del cache.

Sin embargo, el cambio **no commiteado** que migra el token Sanctum de `localStorage` a una cookie `httpOnly` introduce una **vulnerabilidad CSRF crítica y explotable**, porque no se acompañó de ninguna defensa CSRF equivalente, y añade un **endpoint de diagnóstico (`CorsDiagnosticController`) que filtra el token Bearer completo** en el cuerpo de la respuesta, accesible a cualquier usuario autenticado sin restricción de entorno. **Este cambio no debe mergearse a `main` sin corregir estos dos puntos.**

Adicionalmente, el fix de colisión de IDs (M-04 en V2) quedó **incompleto**: se corrigió en `ProjectController::log()` y en los generadores de código de proyecto/contratista, pero **tres focos del mismo patrón** (`AIEvaluationController::logEvaluation()`, `ProjectDocumentController::log()`, `ProjectController::importSupplierProposals()`) siguen generando IDs por timestamp sin sufijo aleatorio ni lock — evidencia de que la duplicación de código (`log()` triplicado entre controladores) hizo que el fix de seguridad no se propagara a todos los puntos vulnerables.

---

## 2. Vulnerabilidades y Riesgos Críticos

### 🔴 CRIT-01 — CSRF explotable por la migración a cookie httpOnly (sin protección CSRF)

**Archivos:** `app/Http/Controllers/Api/AuthController.php:61-71`, `app/Http/Kernel.php:41-46`, `config/cors.php:32`, `app/Http/Middleware/TokenFromCookie.php:21-34`

El login setea una cookie `sanctum_token` que en producción usa `SameSite=None; Secure`. `SameSite=None` hace que el navegador adjunte la cookie en peticiones **cross-site**, incluidas las de un `<form>` HTML enviado desde cualquier sitio — CORS solo restringe la *lectura* de la respuesta vía JS, nunca el *envío* del request. El grupo de middleware `api` no incluye `VerifyCsrfToken` (solo existe en `web`, no usado por la API), y `TokenFromCookie` copia la cookie al header `Authorization: Bearer` sin validar origen ni exigir un token anti-CSRF.

**Explotación concreta:** un usuario autenticado (p. ej. rol `FINANZAS`) visita una página con un `<form>` auto-enviado hacia `POST /api/projects/PRJ-001/payments` con `paymentType=FINAL&amount=999999`. El navegador adjunta la cookie automáticamente, `TokenFromCookie` la traduce a Bearer, Sanctum autentica, y el pago se ejecuta sin que la víctima note nada. El mismo vector aplica a `select-contractor`, `approve-investment`, `report-finished`, `verify-completion`, `users/{id}/toggle-status`. **Antes** de este cambio (Bearer manual en `localStorage`, adjuntado por JS) este vector no existía, porque un `<form>` no puede fijar headers personalizados.

Laravel/Sanctum ya resuelve exactamente este caso con el flujo SPA nativo (`EnsureFrontendRequestsAreStateful` + cookie `XSRF-TOKEN`) — esa línea está comentada en `Kernel.php`; el equipo reimplementó manualmente el mecanismo de cookie sin replicar la protección CSRF que ese flujo incluye de fábrica.

**Remediación:** usar el flujo SPA nativo de Sanctum, o si se mantiene la cookie manual, agregar un token CSRF de doble-submit verificado en cada request mutante y validar `Origin`/`Sec-Fetch-Site` en `TokenFromCookie`.

### 🔴 CRIT-02 — `CorsDiagnosticController` filtra el token Bearer completo, sin rol ni gate de entorno

**Archivo:** `app/Http/Controllers/Api/CorsDiagnosticController.php:16-50`, ruta en `routes/api.php:38-39`

El método itera todo `$_SERVER` y copia cualquier `HTTP_*`, incluido `HTTP_AUTHORIZATION`. `GET /api/cors-check` está dentro del grupo `auth:sanctum` general — **cualquier usuario autenticado, de cualquier rol**, puede invocarlo, sin gate de entorno (`app()->environment('local')`). La respuesta JSON expone el token completo en texto plano. Cualquier sistema que registre cuerpos de respuesta (APM, proxy corporativo, captura de Network tab para soporte) terminaría persistiendo el token de sesión.

Además, `POST /api/cors-refresh` permite a cualquier usuario autenticado forzar la creación de un token nuevo en cada llamada (sin relación con expiración real, infla `personal_access_tokens`), y hardcodea `secure=true` en la cookie sin el chequeo de entorno que sí tiene `AuthController` — usarlo en desarrollo sobre HTTP hace que el navegador descarte la cookie silenciosamente.

**Remediación:** eliminar el endpoint antes de producción, o como mínimo redactar `Authorization`/`Cookie` de la respuesta, restringir a `role:SUPERADMIN` + entorno `local`, y alinear `cors-refresh` con la lógica de `RefreshSanctumToken`.

### 🔴 CRIT-03 — Dump de base de datos versionado con PII real y hash de contraseña administrativa

**Archivo:** `ivoo_gestion_infraestructura.sql:379` (confirmado trackeado en git)

`INSERT INTO users ... ('Arcadio Arevalo', 'admin@ivoo.local', ..., '$2y$10$9PmJ/...')`. El repositorio contiene nombre real, correo y hash bcrypt de una cuenta administrativa. Confirma con evidencia concreta el hallazgo M-09 de V2 ("dump BD versionado"): no es solo estructura, son datos reales/production-like con PII.

*Nota positiva verificada:* `.env` **no** aparece en el historial de git de este repo (`git log --all -- .env` vacío) y está en `.gitignore` — el hallazgo previo sobre `.env` commiteado no se pudo reproducir en el estado actual; conviene confirmar en el remoto si hubo reescritura de historial.

**Remediación:** quitar el `.sql` del control de versiones, reemplazar por seeder con datos ficticios, rotar la contraseña de `admin@ivoo.local`.

### 🟠 Alto

| # | Hallazgo | Archivo:línea |
|---|---|---|
| A-1 | Colisión de IDs (fix M-04 incompleto): timestamp sin sufijo aleatorio ni lock en 3 focos → PK duplicada bajo concurrencia, auditoría silenciada por `catch` genérico | `AIEvaluationController.php:125`, `ProjectDocumentController.php:171`, `ProjectController.php:235` (`importSupplierProposals`, sin el `Str::random(4)` que sí tiene `addProposal():156`) |
| A-2 | `SupplierInvitation` sin campo `expires_at` — link de invitación válido indefinidamente si nunca se usa | `app/Models/SupplierInvitation.php:37-40` |
| A-3 | API key de Gemini en query string de la URL (expuesta en logs/proxy/Referer), duplicado en 2 lugares | `GeminiProvider.php:26`, `AiConfigController.php:327` |
| A-4 | Validación SSRF de `base_url` no cubre DNS rebinding — un dominio público puede resolver en runtime a IP interna/metadata sin ser detectado | `AiConfigController.php:362-404` |
| A-5 | Política de contraseñas débil: solo `min:8`, sin complejidad ni `Password::uncompromised()` | `UserController.php:37` |
| A-6 | Flujo de proyecto sin guardas de estado en pagos/finalización — ver sección 3 | `ProjectController.php:316-366` |
| A-7 | Límite de 2 sesiones activas no invalida la cookie httpOnly de la sesión desalojada (solo el token en BD) | `AuthController.php:36-38` |
| A-8 | Constante `CONTRACTOR_STATUSES` fuera de la clase (mismo patrón ya corregido en `UserController` pero no aquí) | `ContractorController.php:11` |

---

## 3. Cumplimiento de Reglas de Negocio

El flujo de estados de `Project` (`CREADO → REVISADO_CIERRE → CONFIRMADO_PROCURA → COMPARATIVA_ENVIADA → CONTRATADO → EN_EJECUCION → VERIFICANDO_FINALIZACION → LISTO_PAGO_FINAL → COMPLETADO_PAGADO`) exige que cada transición solo pueda invocarse desde el estado de origen correcto. Verificación método por método en `ProjectController.php`:

| Método | ¿Valida estado de origen? | Línea |
|---|:---:|---|
| `rejectProposals()` | ✅ `abort_unless($project->status === COMPARATIVA_ENVIADA, ...)` | 275 |
| `submitComparative()` | ⚠️ Parcial (solo valida existencia de propuestas) | 174 |
| `selectContractor()` | ❌ No valida estado | 296-314 |
| **`pay()`** | ❌ **No valida estado** | 316-339 |
| **`reportFinished()`** | ❌ **No valida estado** | 341-347 |
| **`verifyCompletion()`** | ❌ **No valida estado** | 349-366 |

El middleware `role:FINANZAS,ADMIN,SUPERADMIN` solo verifica el *rol*, no el *estado* del proyecto. Un usuario `FINANZAS` puede invocar `POST /projects/{id}/payments` con `paymentType=FINAL` sobre un proyecto recién `CREADO`, saltándose adjudicación, ejecución y verificación de calidad. Más grave: si se invoca `paymentType=ADVANCE` sobre un proyecto ya `COMPLETADO_PAGADO`, el `updateOrCreate` de `ProjectPayment` (líneas 325-333) actualiza el registro existente y el `update()` de la línea 335 **regresa el status a `EN_EJECUCION`**, revirtiendo un proyecto ya cerrado. No hay ninguna restricción de BD que lo impida — solo disciplina del frontend, que no es garantía de integridad del backend.

`tests/Feature/ProjectLifecycleTest.php` solo ejercita la secuencia feliz en orden; ningún test intenta `pay(FINAL)` antes de `pay(ADVANCE)` ni una transición desde un estado incorrecto. Este hallazgo ya estaba señalado en V2 (sección 3) y sigue **totalmente sin resolver**, ahora confirmado y extendido a `reportFinished`/`verifyCompletion`.

---

## 4. Mejoras: Clean Code, POO y Normalización

| Archivo:línea | Hallazgo | Principio |
|---|---|---|
| `ContractorController.php:11` | Const global fuera de clase | Inconsistente con el fix ya aplicado en `UserController` (M-01) |
| `SupportController.php:264-275` vs `ContractorController.php:130-141` | `nextContractorCode()` duplicado carácter por carácter | DRY / Shotgun Surgery |
| `ProjectController.php:386-400`, `ProjectDocumentController.php:167-181`, `AIEvaluationController.php:119-147` | Método `log()`/`logEvaluation()` casi idéntico triplicado, cada uno con su propia generación de ID de auditoría | DRY — causa raíz directa del hallazgo A-1 (el fix de seguridad de IDs no se propagó a los 3 sitios) |
| `AIEvaluationController.php:52` | `Rule::in('chatgpt', 'gemini', 'claude')` sin envolver en array | Frágil ante cambios de firma de Laravel |
| `AiConfiguration.php:51-59` (diff sin commitear) | `getMaskedApiKey()` ya no se invoca — `toArray()` implementa su propio enmascarado inline | Código muerto introducido por el propio diff en revisión |
| `AnthropicProvider.php:28`, `AiConfigController.php:301` | `anthropic-version: '2023-06-01'` hardcodeada en 2 lugares | Magic string duplicado |
| `config/cors.php:18` | `paths` incluye `'login'`/`'logout'` sin prefijo `api/` (las rutas reales son `api/login`) | Configuración inerte |
| `ProjectController.php` (393L), `SupportController.php` (289L) | Controladores gestionan CRUD + lifecycle + import + auditoría + generación de IDs | Violación SRP |
| `AIEvaluationService.php:34-78` | `registerProviders()` mezcla resolución de config, instanciación concreta (`new $class(...)`) y filtrado | DIP — sigue sin factory/container |

**Fortalezas confirmadas:** `BaseAIProvider` correctamente abstracta (M-06), DTOs `EvaluationPayload/Project/Proposal` en uso real (B-03), inyección de dependencias explícita en `AIEvaluationService`/`AiConfigController` (M-07).

---

## 5. Estado de Testing

- **`CookieAuthFlowTest.php`** (nuevo, sin commitear): 5 tests que cubren el *happy path* (Set-Cookie presente, httpOnly, borrado en logout). **Cero tests de seguridad**: ninguno verifica rechazo de CSRF, validación de `Origin`, ni que `cors-check`/`cors-refresh` requieran rol o entorno restringido — coherente con que CRIT-01 y CRIT-02 no fueran detectados antes de este punto.
- **`ProjectLifecycleTest.php`** — solo confirma la secuencia feliz de pagos; no cubre el gap de la sección 3 (pagos/transiciones fuera de orden).
- **Endpoints de diagnóstico** (`/api/cors-check`, `/api/cors-refresh`) — sin tests.
- Sin cambios respecto a V2: AI Config CRUD, AI Evaluation (lógica real), Project Documents, PushToken y comandos de consola (incluido el nuevo `DiagnoseCookieAuth`) siguen sin cobertura automatizada.
- Rendimiento: no se detectaron N+1 nuevos en los controladores revisados; paginación y eager loading correctos en `ProjectController::index()`. Único punto notado: `AiConfigurationService::getApiKey()` hace una query por provider en cada evaluación IA (trade-off correcto tras eliminar el cache de keys en claro, impacto real bajo).

---

## 6. Conclusión y Siguientes Pasos

El trabajo de remediación de la auditoría V2 fue **real y verificable** en la mayoría de los puntos (13 de los ítems M-01 a M-08 y B-01 a B-06 confirmados resueltos en código, no solo en mensajes de commit). Sin embargo, el diff pendiente de commit que migra la autenticación a cookie httpOnly —hecho con buena intención (eliminar exposición del token a XSS vía `localStorage`)— introduce el **riesgo de seguridad más severo detectado hasta ahora** en este backend: un CSRF explotable sobre acciones financieras y contractuales, agravado por un endpoint de diagnóstico que filtra el token de sesión.

**Bloqueante antes de mergear a `main`:**
1. Corregir CRIT-01 (agregar protección CSRF real: flujo SPA nativo de Sanctum o doble-submit token).
2. Corregir o eliminar CRIT-02 (`CorsDiagnosticController`).

**Siguiente sprint (alto):**
3. Completar el fix de colisión de IDs en los 3 focos restantes (A-1), idealmente extrayendo un `AuditLogService` único que elimine la triplicación de `log()`.
4. Agregar guardas de estado a `pay()`, `reportFinished()`, `verifyCompletion()` (A-6 / sección 3) — es una brecha de integridad financiera, no solo de código.
5. Sacar `ivoo_gestion_infraestructura.sql` del control de versiones y rotar la contraseña admin expuesta (CRIT-03).

**Pendientes de deuda técnica ya conocidos** (A-2, A-3, A-4 residual, A-5, A-7, A-8 y los ítems de Clean Code de la sección 4) — mantener en el backlog de PENDIENTES, sin bloquear el release si CRIT-01/02/03 quedan resueltos.

No se modificó ningún archivo de código como parte de esta auditoría — solo lectura y análisis, conforme al rol de auditor.
