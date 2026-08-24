# CHANGELOG

## [2026-08-24] — Docs: LEGACY.md con el trabajo no cubierto por bitácoras
- Tipo: docs
- Qué: nuevo `docs/LEGACY.md` redactado desde el historial git (74 commits). Cubre todo lo anterior al 17-08-2026 y los commits de backend no registrados en las bitácoras de Notion, con énfasis en los paneles de configuración: Proveedores CRUD (`37b414c`), Materiales CRUD (`734618f`), Config IA (`74912c7`), CONFIG APP Fase 1.4 (`3f49a7b`/`c37bbb1`), Monedas (`f374318`), fixes transversales del panel (`70823e6`, `d9c276a`) y el sistema de notificaciones configurable en 4 fases (`6f64147`, `ddde7e7`, `726eedf`, `d21f822`).
- Por qué / causa raíz: complemento de `docs/MEJORAS-BITACORAS-08-2026.md` — documentar la historia previa que las bitácoras no cubren.
- Archivos: `docs/LEGACY.md` [NUEVO].

## [2026-08-24] — Docs: consolidado de mejoras de bitácoras Notion (agosto 2026)
- Tipo: docs
- Qué: nuevo `docs/MEJORAS-BITACORAS-08-2026.md` que consolida las 4 bitácoras de seguimiento de Notion (17, 20, 21 y 24-08-2026): normalización del design system, cierre de Fase 1 (rol MARKETING + RejectionService), versionado de documentos, flujo de rechazo/reenvío de solicitudes, rediseño INFRA/CIERRE y fixes de previsualizador/adjuntos.
- Por qué / causa raíz: solicitud del usuario de un documento único con todas las mejoras realizadas según las bitácoras externas al repo.
- Archivos: `docs/MEJORAS-BITACORAS-08-2026.md` [NUEVO].

## [2026-08-14] — Fix: cambios en vistas de configuración no aparecían en vivo en auditlogs
- Tipo: fix (bug de dos capas: backend + frontend)
- Qué: `AiConfigController`, `ContractorController`, `MaterialController` y `UserController` no devolvían el `ConfigAuditLog` recién creado en la respuesta de sus endpoints mutadores (store/update/destroy/toggleStatus) — a diferencia de `CurrencyController`/`AppSettingController`, que ya lo hacían desde una sesión previa. Cada uno ahora agrega `auditLog` (payload vía `ConfigAuditLog::toApiPayload()`) como hermano de los campos del resource ya existente. Caso especial: `UserController::update()` puede generar 2 entradas en una sola request (modificación de datos + cambio de rol), así que expone `auditLogs` (array) en vez de `auditLog` singular.
- Por qué / causa raíz: el backend sí registraba la auditoría correctamente en todos los casos; el frontend de 4 de 5 vistas de configuración (`AIConfigPanel`, `ProveedoresConfigPanel`, `MaterialConfigPanel`, `UsuariosPanel`) nunca tenía el dato para insertarlo en vivo en el panel de auditlogs (`prependLocal` de `useConfigAuditLogs`), a diferencia de `ConfigAppPanel` que sí estaba conectado. Sin este dato en la respuesta, el usuario tenía que refrescar la página para ver su propio cambio reflejado.
- Archivos: modificados `app/Http/Controllers/Api/AiConfigController.php`, `ContractorController.php`, `MaterialController.php`, `UserController.php`; tests actualizados en `AiConfigCrudTest.php`, `ContractorMaterialTest.php`, `UserManagementTest.php` (incluye nuevo test para el caso de doble entrada de auditoría en cambio de rol). Frontend: `useAIConfig.ts`, `useUsuarios.ts` tipan las respuestas de mutación con `auditLog`/`auditLogs` opcional; las 4 vistas ahora llaman `prependAuditLog` tras cada mutación exitosa (gateado por `isSuperadmin`, igual que el resto del panel de auditoría).
- Verificación: **349/349 tests backend, 807/807 tests frontend.**

## [2026-08-13] — Catálogo real de acciones auditadas centralizado + endpoint para el selector de tags
- Tipo: feature (UX) + refactor
- Qué: `NotificationDispatcher::AUDITABLE_ACTIONS` centraliza las 16 acciones que la app efectivamente audita/notifica (antes duplicadas como array literal dentro de la migración `2026_08_13_000001`). Nuevo endpoint `GET /settings/notification-actions` (cualquier autenticado) devuelve ese catálogo, consumido por el selector de tags de `acciones_con_correo` / `acciones_con_notificacion_app` en CONFIG APP — así el frontend nunca ofrece una acción que la app no dispara realmente. La migración ahora usa la constante como default en vez de repetir la lista.
- Por qué / causa raíz: el usuario pidió reemplazar el textarea JSON crudo de esas dos listas por un selector de tags, con la condición explícita de que las opciones fueran las acciones reales que la app dispara — no una lista inventada en el frontend. Centralizar en una constante evita que la migración y el endpoint diverjan con el tiempo.
- Archivos: modificados `app/Services/NotificationDispatcher.php` (nueva constante `AUDITABLE_ACTIONS`), `app/Http/Controllers/Api/AppSettingController.php` (+`notificationActions()`), `database/migrations/2026_08_13_000001_rework_notification_settings.php` (usa la constante), `routes/api.php`; test nuevo en `tests/Feature/AppSettingTest.php`.
- Verificación: **231/231 tests backend.**

## [2026-08-13] — GET /config-audit-logs pasa a paginación numerada server-side
- Tipo: feature (escalabilidad)
- Qué: `ConfigAuditLogController::index()` ahora acepta `page` y `per_page` (default 20, tope 200) y devuelve `{items, currentPage, lastPage, total, perPage}` anidado dentro de `data` (mismo motivo que `auditLog` en el PATCH de settings: `apiFetch` desenvuelve `json.data` automáticamente, así que la metadata de paginación no puede ir como hermana de `data`).
- Por qué / causa raíz: el endpoint ya usaba `paginate()` de Laravel pero el controlador devolvía el objeto paginador completo sin envolver en `data`, y el frontend descartaba toda la metadata quedándose solo con el array de la página — funcionaba porque el historial todavía tenía pocos registros, pero no escala: con cientos o miles de cambios de configuración acumulados, cargar todo de una vez en el panel sería inviable.
- Archivos: modificado `app/Http/Controllers/Api/ConfigAuditLogController.php`; tests en `tests/Feature/ConfigAuditLogTest.php` actualizados al nuevo shape (`data.items.*`, `data.currentPage`, `data.total`, `data.perPage`) + nuevo test de paginación con 25 registros en 2 páginas.
- Verificación: **230/230 tests backend.**

## [2026-08-13] — Notificaciones: acciones configurables, retención con purga automática, y auditoría de CONFIG APP exclusiva de SUPERADMIN
- Tipo: feature
- Qué:
  - Eliminados los 4 settings "correo por departamento" (`correo_procura/finanzas/cierre_obra/auditoria`) — nunca tuvieron consumidor: `ProjectActionMail` siempre envía al destinatario real de la notificación (mismo criterio por rol que push/bandeja), nunca a una dirección fija de departamento.
  - Nuevo setting `acciones_con_notificacion_app`: mismo patrón que `acciones_con_correo` pero para push + bandeja interna (`NotificationDispatcher::notify()`). Por defecto incluye las ~16 acciones auditadas (comportamiento actual sin cambios); editable desde CONFIG APP para silenciar acciones de bajo valor sin dejar de auditarlas — `AuditLog::record()` sigue corriendo siempre, solo se filtra el aviso.
  - Nuevo setting `retencion_notificaciones_dias` (default 90, rango 7-365) + comando `notifications:prune`, programado diario en el scheduler junto a `sanctum:clear-expired-tokens`. Antes las notificaciones en `app_notifications` se acumulaban indefinidamente, sin ningún mecanismo de limpieza.
  - Nuevos settings `polling_notificaciones_segundos` (default 8, rango 5-120) y `polling_dashboard_segundos` (default 25, rango 10-300) — catálogo listo para conectarse en una fase futura (no conectados todavía, solo el dato queda disponible en CONFIG APP).
  - **Auditoría de CONFIG APP separada y exclusiva de SUPERADMIN**: nueva tabla `config_audit_logs` + modelo `ConfigAuditLog` + `GET /config-audit-logs` (middleware `role:SUPERADMIN`), deliberadamente distinta de `audit_logs`/`GET /audit-logs` (visible para cualquier autenticado, incluida Presidencia). `AppSettingController::update()` registra cada cambio (valor viejo, valor nuevo, usuario, timestamp) y devuelve la entrada recién creada anidada en la respuesta (`data.auditLog`) para que el frontend la inserte sin una consulta adicional.
- Por qué / causa raíz: el usuario señaló que el apartado "Correos" de notificaciones no reflejaba cómo funciona la app (esos 4 campos eran configuración muerta) y pidió el equivalente de `acciones_con_correo` para notificaciones in-app, además de que la auditoría de cambios de configuración quedara restringida a SUPERADMIN, invisible para Presidencia (a diferencia de la auditoría de proyectos).
- Archivos: nuevas migraciones `database/migrations/2026_08_13_000001_rework_notification_settings.php`, `2026_08_13_000002_create_config_audit_logs_table.php`; nuevos `app/Models/ConfigAuditLog.php`, `app/Http/Controllers/Api/ConfigAuditLogController.php`, `app/Console/Commands/PruneOldNotifications.php`; modificados `app/Services/NotificationDispatcher.php`, `app/Http/Controllers/Api/AppSettingController.php`, `app/Console/Kernel.php`, `routes/api.php`; tests nuevos `tests/Feature/PruneOldNotificationsTest.php` (2), `tests/Feature/ConfigAuditLogTest.php` (5, incluye la respuesta anidada del PATCH), +2 en `tests/Feature/NotificationDispatcherTest.php` (filtro de acciones con notificación app).
- Verificación: **228/228 tests backend.**

## [2026-08-12] — Fix crítico: el registro/evaluación de ofertas rechazaba anticipos renegociados por encima del máximo
- Tipo: fix (bug bloqueante introducido en esta misma sesión)
- Qué: `AddProjectProposalRequest` (registro de propuesta por Analistas) y `AIEvaluationController::evaluate()` (evaluación IA de Procura) seguían validando `negotiatedAdvancePercent` con `max:{$maxAdvance}` contra el setting configurable — rechazando con 422 cualquier intento de cargar una oferta con anticipo renegociado por encima de la política interna (ej. 40% cuando el máximo configurado es 30%). Se revirtió a un tope fijo de sanidad `max:100` en ambos, igual que ya se había hecho para el link público de proveedores.
- Por qué / causa raíz: el diseño de negocio correcto (confirmado por el usuario) es que el máximo configurado en CONFIG APP sea solo una **alerta visual** para Analistas/Procura, nunca un bloqueo — existen casos reales de renegociación telefónica o directa con el proveedor donde el anticipo pactado excede la política interna, y el sistema debe permitir registrar esa condición real, no rechazarla. La alerta (ya implementada en `BidRegistrationSection`, `ComparativeTableSection`, `BidEvaluationSection` y `HireConfirmDialog`) es la forma correcta de que Analistas/Procura vean el riesgo sin que el dato se pierda.
- Archivos: modificados `app/Http/Requests/AddProjectProposalRequest.php`, `app/Http/Controllers/Api/AIEvaluationController.php`; tests ajustados en `tests/Feature/ProjectLifecycleTest.php` y `tests/Feature/AiEvaluationTest.php` (reemplazados los tests que esperaban 422 configurable por: aceptación por encima del máximo configurado, y rechazo solo por encima del techo de sanidad 100%).
- Verificación: **220/220 tests backend.**

## [2026-08-12] — Corrección: el link público de proveedores NO respeta el anticipo máximo configurado
- Tipo: fix (corrección de diseño de negocio)
- Qué: revertido `SupplierProposalController::store()` y `SupplierInvitationController::publicInfo()` — el proveedor externo (formulario público sin sesión, vía token de invitación) vuelve a validar con un tope fijo `max:100`, no con `SettingsService::get('anticipo_maximo_porcentaje')`. Se eliminó `maxAdvancePercent` del payload de `publicInfo()`.
- Por qué / causa raíz: el usuario señaló que el proveedor externo no debe estar sujeto a una política interna que ni siquiera conoce el motivo — su cotización debe reflejar su condición real. Quien sí necesita ver si esa oferta excede la política interna es el Analista/Procura al evaluarla, no el proveedor al momento de cotizar. La validación con el setting configurable se mantiene en los 2 flujos internos autenticados (`AddProjectProposalRequest`, `AIEvaluationController`).
- Archivos: modificados `app/Http/Controllers/Api/SupplierProposalController.php`, `app/Http/Controllers/Api/SupplierInvitationController.php`; tests ajustados en `tests/Feature/SupplierInvitationTest.php` (reemplazado el test que esperaba 422 configurable por dos tests: rechazo por techo fijo de sanidad 100%, y aceptación por encima del máximo configurado ya que el link no lo respeta).
- Verificación: **218/218 tests backend.**

## [2026-08-12] — Anticipo máximo (CONFIG APP) conectado a toda validación de anticipo en la app
- Tipo: fix
- Qué: el setting `presupuesto.anticipo_maximo_porcentaje` (hasta ahora solo editable en CONFIG APP, sin consumidor) ahora es la única fuente de verdad para el tope de anticipo en los 3 puntos donde el backend valida ese campo — se eliminó el `max:100` hardcodeado de los tres:
  - `AddProjectProposalRequest::rules()` — alta de propuesta de contratista (`POST /projects/{project}/proposals`).
  - `AIEvaluationController::evaluate()` — validación inline de `proposals.*.negotiatedAdvancePercent` antes de mandar a evaluación IA.
  - `SupplierProposalController::store()` — envío público (sin auth, vía token de invitación) de cotización de proveedor de materiales.
  - `SupplierInvitationController::publicInfo()` ahora expone `maxAdvancePercent` en el payload público de la invitación, para que el formulario público de proveedores sepa qué tope mostrar/validar sin necesitar sesión (la ruta `/settings` requiere autenticación).
- Por qué / causa raíz: el usuario pidió conectar el anticipo máximo a "todo lo que tenga que ver con anticipos", para que un cambio de política (ej. bajar el tope al 50%) se refleje de inmediato en los 3 flujos de captura sin deploy, en vez de solo en el panel de configuración.
- Archivos: modificados `app/Http/Requests/AddProjectProposalRequest.php`, `app/Http/Controllers/Api/AIEvaluationController.php`, `app/Http/Controllers/Api/SupplierProposalController.php`, `app/Http/Controllers/Api/SupplierInvitationController.php`; tests nuevos/extendidos en `tests/Feature/ProjectLifecycleTest.php` (+1), `tests/Feature/AiEvaluationTest.php` (+1), `tests/Feature/SupplierInvitationTest.php` (+2, incluye la nueva estructura JSON de `publicInfo`).
- Verificación: **218/218 tests backend.**

## [2026-08-12] — CONFIG APP: rango min/max para settings numéricos acotados
- Tipo: fix
- Qué:
  - Nuevas columnas `min_value`/`max_value` (decimal nullable) en `app_settings`, pobladas para los 5 settings porcentuales (`anticipo_maximo_porcentaje`, los 3 umbrales de semáforo, `alerta_precio_umbral_porcentaje`) con rango 0–100.
  - `AppSettingController::update()` valida el rango además del tipo: si el nuevo valor está fuera de `[min_value, max_value]` responde 422, en vez de confiar solo en la validación del frontend.
- Por qué / causa raíz: el usuario notó que el panel permitía guardar porcentajes fuera de rango (>100, negativos) sin aviso. Los límites deben vivir junto al dato en BD para que cualquier cliente (panel, futura API externa) los respete, no solo el formulario actual.
- Archivos: nueva `database/migrations/2026_08_12_000003_add_min_max_to_app_settings_table.php`; modificados `app/Models/AppSetting.php`, `app/Http/Controllers/Api/AppSettingController.php`; +2 tests en `tests/Feature/AppSettingTest.php` (rechazo por debajo/encima del rango).
- Verificación: **214/214 tests backend.**

## [2026-08-12] — Plan 90 días, Fase 1.4: CONFIG APP (parámetros de negocio editables sin deploy)
- Tipo: feature
- Qué:
  - Tabla genérica `app_settings` (key/value tipado: string|integer|float|boolean|json, agrupado por `group`, con `label`/`description` para el panel de administración). Sembrada con 18 defaults que reproducen exactamente los valores hoy hardcodeados en el código (anticipo 100%, semáforo 80/95/100, acciones de correo) — desplegar esta migración no cambia comportamiento, solo lo hace editable.
  - `App\Models\AppSetting` con accessor `cast_value` (casteo por `type`).
  - `App\Services\SettingsService`: punto único de lectura tipada (`get(key, default)`, `all()`), cacheado 5 min (driver `file`), invalidado explícitamente en cada `update()` — un cambio desde CONFIG APP se refleja de inmediato, no hay que esperar el TTL.
  - `AppSettingController`: `GET /settings` (cualquier autenticado, agrupado), `PATCH /settings/{setting}` (solo SUPERADMIN/ADMIN, valida el nuevo valor según `type` — entero/booleano/JSON bien formados).
  - **Primer consumidor real conectado**: `NotificationDispatcher::MAIL_ACTIONS` (constante hardcodeada desde Fase 1.1/1.2) ahora lee `SettingsService::get('acciones_con_correo', ...)` — la lista de acciones que disparan correo es editable desde CONFIG APP sin deploy, con fallback a las mismas 4 acciones de antes si el setting no existe.
- Por qué / causa raíz: Fase 1.4 del plan de 90 días (`docs/PLAN-MAESTRO-90-DIAS.md`) — todo lo configurable (montos, límites, correos, pesos, umbrales) debe vivir en CONFIG, nunca hardcodeado. Se decidió construir las 9 áreas completas del plan de una vez (moneda, presupuesto/anticipo, ratings, notificaciones, fiscal, alertas de precio, inflación, app) en vez de solo lo que ya tiene consumidor hoy, para no volver a tocar el panel en cada fase futura.
- Archivos: nuevos `database/migrations/2026_08_12_000002_create_app_settings_table.php`, `app/Models/AppSetting.php`, `app/Services/SettingsService.php`, `app/Http/Controllers/Api/AppSettingController.php`; modificados `app/Services/NotificationDispatcher.php`, `routes/api.php`, `config/permissions.php` (nueva ruta frontend `/config-app` para SUPERADMIN/ADMIN); tests nuevos `tests/Feature/AppSettingTest.php` (8 tests), `tests/Feature/SettingsServiceTest.php` (5 tests), +1 test en `tests/Feature/NotificationDispatcherTest.php` (lista de acciones editable sin deploy).
- Verificación: **212/212 tests backend.**

## [2026-08-12] — Plan 90 días, Fase 1.1: notificaciones unificadas vía AuditLog::record()
- Tipo: feature
- Qué:
  - Nuevo `App\Services\NotificationDispatcher::notify()`: punto único que decide destinatarios por rol según el status del proyecto y despacha push (`ProjectActionNotification`, antes `ProjectStatusChanged`) a cada uno.
  - `AuditLog::record()` ahora llama a `NotificationDispatcher::notify()` internamente tras persistir el log — cualquier acción que ya audite (16 call sites en `ProjectController`, `ProjectDocumentController`, `AIEvaluationController`, `LogsPublicAccess`) notifica automáticamente sin tocar esos call sites.
  - `ProjectObserver` (escuchaba solo cambios de `status` vía `updated()`) se eliminó — quedaba desincronizado de acciones que auditan sin cambiar status (ej. rechazo de cuadro comparativo). Su matriz rol→destinatarios se movió tal cual a `NotificationDispatcher::recipientsFor()`, con una corrección: `LISTO_PAGO_FINAL` no tenía destinatarios en el observer (bug latente, nadie recibía push en ese estado); ahora notifica a `FINANZAS` (quien libera el pago final), consistente con el resto de la matriz.
  - `ProjectStatusChanged` renombrado a `App\Notifications\ProjectActionNotification`; su constructor pasó de `(project, oldStatus, newStatus)` a `(project, action, status)` porque ahora el mensaje push refleja la acción auditada real (ej. "Rechazo de cuadro comparativo"), no solo el nombre del estado.
- Por qué / causa raíz: Fase 1.1 del plan de 90 días (`docs/PLAN-MAESTRO-90-DIAS.md`) pide un único punto de entrada de notificaciones que ningún módulo dispare por su cuenta. La auditoría técnica (`docs/FASE0-AUDITORIA-TECNICA.md`) detectó que ya existían dos mecanismos paralelos y parcialmente redundantes (`AuditLog::record()` manual + `ProjectObserver` automático); se unificaron en uno solo en vez de construir un tercero desde cero.
- Archivos: nuevo `app/Services/NotificationDispatcher.php`; renombrado `app/Notifications/ProjectStatusChanged.php` → `ProjectActionNotification.php`; eliminado `app/Observers/ProjectObserver.php`; modificados `app/Models/AuditLog.php`, `app/Providers/AppServiceProvider.php`; nuevo `tests/Feature/NotificationDispatcherTest.php` (4 tests: destinatarios correctos por status, status sin destinatarios no notifica, `LISTO_PAGO_FINAL` notifica a FINANZAS, payload de la notificación lleva la acción real).
- Pendiente dentro de 1.1: canal de "alertas internas" persistentes (bandeja in-app) y Web Push para el frontend — hoy solo hay push mobile (ya cubierto por este dispatcher) y correo (fuera de este alcance, sin cambios). Correo por evento de dominio no está conectado todavía.
- Verificación: 193/193 tests (189 previos + 4 nuevos). **Pendiente de commit por el usuario.**

## [2026-08-12] — Refactor SOLID/CleanCode Fase 4: route-model binding en AiConfigController + regla de password unificada
- Tipo: refactor
- Qué:
  - `AiConfigController::show/update/destroy/test` pasaron de `int $id` + `AiConfiguration::findOrFail($id)` a route-model binding (`AiConfiguration $aiConfig`), consistente con el resto de controllers. Rutas en `routes/api.php` actualizadas de `{id}` a `{aiConfig}` (mismo path público, solo cambia el nombre interno del parámetro).
  - Se creó `App\Rules\StrongPassword::rule()` (mismas reglas: `min(8)->mixedCase()->numbers()`) y se reemplazó la definición duplicada que vivía como `UserController::passwordRule()` y directamente inline en `AuthController::resetPassword`.
- Por qué / causa raíz: Fase 4 (consistencia menor) del refactor SOLID/CleanCode — alinear `AiConfigController` con el patrón de route-model binding ya usado en el resto de la app, y eliminar la duplicación de la política de contraseña entre `AuthController` y `UserController`.
- Archivos: `app/Rules/StrongPassword.php` [NUEVO]; modificados `AiConfigController`, `UserController`, `AuthController`, `routes/api.php`.
- Verificación: 189/189 tests, 67/67 rutas (mismos paths públicos). **Pendiente de commit por el usuario.**

## [2026-08-12] — Refactor SOLID/CleanCode Fase 3: extracción de servicios de dominio + split de SupportController
- Tipo: refactor
- Qué:
  - `AiConfigController::test()` delega el health-check a `AIProviderFactory::make(...)->healthCheck()`; se agregó `healthCheck()` a `AIProviderInterface`/`BaseAIProvider` e implementación por proveedor (`OpenAIProvider`, `AnthropicProvider`, `GeminiProvider`) reusando exactamente las URLs/mensajes que antes vivían como métodos privados en el controller. Se eliminaron `testOpenAI/testAnthropic/testGemini`.
  - `AiConfigController::usage()` delega a `App\Services\AI\AiUsageAnalyticsService::getUsageSummary(int $days)` (mismas 4 queries de agregación, mismo shape de respuesta).
  - `DashboardSummaryController` pasó de calcular todo en `__invoke()` (140 líneas) a delegar en `App\Services\DashboardSummaryService::getSummary()`; constantes `COMMITTED_STATUSES`, `STATUS_ORDER` y el umbral de 14 días (`STALLED_THRESHOLD_DAYS`) se movieron al servicio.
  - `ProjectController::importSupplierProposals()` delega el matching/cálculo/persistencia a `App\Services\SupplierProposalImportService::import()`; el controller solo arma la respuesta y registra el `AuditLog`.
  - `ProjectDocumentController`: `sanitizeFilename()`/`uniqueFilename()` se extrajeron a `App\Services\DocumentStorageService`. `syncProjectCounts()` se dejó en el controller (moverla a un observer de `ProjectDocument` cambiaría el timing — hoy corre una sola vez tras el batch de `upload()`, un observer correría por cada documento creado).
  - `SupportController` (grab-bag de 5 recursos no relacionados) se eliminó y se repartió: `ModuleController@index`, `AuditLogController@index`, `SupplierInvitationController@store/publicInfo`, `SupplierProposalController@store/index`, y `ContractorController@activeList/registerPublic/updateRating` + `MaterialController@activeList` (quedan junto al resto del CRUD de cada recurso en vez de un controller de soporte genérico). El helper `logPublicAccess()` se extrajo a un trait compartido `App\Http\Controllers\Concerns\LogsPublicAccess` (usado por `ContractorController`, `SupplierInvitationController`, `SupplierProposalController`). Ningún path/verbo/middleware de ruta cambió — solo la clase/método destino en `routes/api.php`.
- Por qué / causa raíz: Fase 3 del refactor SOLID/CleanCode — sacar lógica de negocio y agregación de los controllers (SRP), eliminar duplicación de llamadas HTTP a proveedores IA ya encapsuladas en `Services/AI/Providers`, y separar el controller grab-bag por recurso.
- Archivos: nuevos `AiUsageAnalyticsService`, `DashboardSummaryService`, `SupplierProposalImportService`, `DocumentStorageService`, `ModuleController`, `AuditLogController`, `SupplierInvitationController`, `SupplierProposalController`, `LogsPublicAccess` (trait); eliminado `SupportController`; modificados `AiConfigController`, `DashboardSummaryController`, `ProjectController`, `ProjectDocumentController`, `ContractorController`, `MaterialController`, `routes/api.php`, providers IA (`AIProviderInterface`, `BaseAIProvider` no se tocó, `OpenAIProvider`, `AnthropicProvider`, `GeminiProvider`).
- Verificación: 189/189 tests, 67/67 rutas (mismos paths/verbos). **Pendiente de commit por el usuario.**

## [2026-08-12] — Refactor SOLID/CleanCode Fase 2: Form Requests
- Tipo: refactor
- Qué: se extrajo la validación inline (`$request->validate([...])`) a Form Requests dedicados en `app/Http/Requests/`: `StoreMaterialRequest`/`UpdateMaterialRequest`, `StoreContractorRequest`/`UpdateContractorRequest` (constante `ContractorController::CONTRACTOR_STATUSES` pasó de `private` a `public` para reutilizarla), `StoreAiConfigurationRequest`/`UpdateAiConfigurationRequest`, y en `ProjectController` un Form Request por acción (`StoreProjectRequest`, `ReviewProjectRequest`, `ApproveInvestmentRequest`, `AddProjectProposalRequest`, `RejectProposalsRequest`, `SelectContractorRequest`, `PayProjectRequest`, `VerifyCompletionRequest`). Los checks de unicidad con mensaje custom (Material/Contractor/AiConfig) se dejaron en el controller para no alterar el shape de respuesta 422.
- Por qué / causa raíz: Fase 2 del refactor SOLID/CleanCode — sacar responsabilidad de validación de los controllers sin cambiar rutas, JSON ni códigos de estado.
- Archivos: 14 Form Requests nuevos en `app/Http/Requests/`; modificados `MaterialController`, `ContractorController`, `AiConfigController`, `ProjectController`.
- Verificación: 189/189 tests, 67/67 rutas sin cambios. **Pendiente de commit por el usuario.**

## [2026-08-12] — Refactor SOLID/CleanCode Fase 1: SsrfSafeUrl rule, API Resources, audit log unificado
- Tipo: refactor
- Qué: se extrajo la regla SSRF de `AiConfigController` a `App\Rules\SsrfSafeUrl` (invokable rule); se crearon API Resources (`MaterialResource`, `ContractorResource`, `ProjectDocumentResource`, `SupplierProposalResource`, `UserResource`, `AiConfigurationResource`) reemplazando el armado manual de arrays, preservando el mismo shape JSON; `SupportController::logPublicAccess()` ahora también persiste en `AuditLog` (además del `Log::info` existente) cuando el acceso público está asociado a un proyecto.
- Por qué / causa raíz: Fase 1 de un refactor SOLID/CleanCode del backend — responsabilidades mezcladas en controllers grandes (validación + queries + formateo manual de respuesta).
- Archivos: `app/Rules/SsrfSafeUrl.php` [NUEVO]; 6 Resources nuevos en `app/Http/Resources/`; modificados `AiConfigController`, `MaterialController`, `ContractorController`, `ProjectDocumentController`, `SupportController`, `UserController`.
- Verificación: 189/189 tests, 67/67 rutas sin cambios. Commit: `7b636cc`.

## [2026-08-11] — Diagrama: eliminadas flechas de rechazo en DIAGRAM_APP.md
- Tipo: docs
- Qué: se quitaron del diagrama Mermaid las aristas `COMPARATIVA_ENVIADA → CONFIRMADO_PROCURA` (Procura rechaza propuestas) y `VERIFICANDO_FINALIZACION → EN_EJECUCION` (Calidad rechazada), junto con el estilo `backStep` que las resaltaba.
- Por qué / causa raíz: solicitud del usuario de simplificar el diagrama (solo `docs/DIAGRAM_APP.md`; `docs/FLUJO_INFRAESTRUCTURA.md` queda intacto).
- Archivos: `docs/DIAGRAM_APP.md`.

---

Entradas anteriores a 2026-08-11 archivadas en [`docs/CHANGELOG_ARCHIVE.md`](docs/CHANGELOG_ARCHIVE.md).

