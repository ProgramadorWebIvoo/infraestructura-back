# CHANGELOG

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

