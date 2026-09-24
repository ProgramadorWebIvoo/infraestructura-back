<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AwardApprovalController;
use App\Http\Controllers\Api\AccessAdminController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ClosureReportController;
use App\Http\Controllers\Api\ContractorController;
use App\Http\Controllers\Api\PublicClosureReportController;
use App\Http\Controllers\Api\MarketingProjectController;
use App\Http\Controllers\Api\MarketingProjectAttachmentController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectDocumentController;
use App\Http\Controllers\Api\ProjectRateFreezeController;
use App\Http\Controllers\Api\RenegotiationInvitationController;
use App\Http\Controllers\Api\SupplierInvitationController;
use App\Http\Controllers\Api\SupplierProposalController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AIEvaluationController;
use App\Http\Controllers\Api\AiConfigController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\ProjectTypeController;
use App\Http\Controllers\Api\RoleController;
use App\Http\Controllers\Api\NotificationActionController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\DashboardSummaryController;
use App\Http\Controllers\Api\AppNotificationController;
use App\Http\Controllers\Api\AppSettingController;
use App\Http\Controllers\Api\SystemKeyConfigController;
use App\Http\Controllers\Api\ConfigAuditLogController;
use App\Http\Controllers\Api\NotificationRuleController;
use App\Http\Controllers\Api\AiFeatureToggleController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\ExchangeRateController;
use App\Http\Controllers\Api\RatingIaController;
use App\Http\Controllers\Api\CatalogCategoryController;
use App\Http\Controllers\Api\CustomProductResolutionController;
use App\Http\Controllers\Api\CatalogProductController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/

Route::post('/login', [AuthController::class, 'login'])->middleware(['throttle:public-api', 'throttle:login']);
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:public-api');
Route::post('/contractors', [ContractorController::class, 'registerPublic'])->middleware('throttle:public-api');
Route::get('/public/invitations/{token}', [SupplierInvitationController::class, 'publicInfo'])->middleware('throttle:public-api');
Route::post('/public/invitations/{token}/proposal', [SupplierProposalController::class, 'store'])->middleware('throttle:public-api');
Route::post('/public/invitations/{token}/proposal-image', [SupplierProposalController::class, 'uploadImage'])->middleware('throttle:public-api');
Route::get('/public/invitations/{token}/proposal-image/{path}', [SupplierProposalController::class, 'image'])
    ->where('path', '.*')
    ->middleware('throttle:public-api');

Route::get('/public/closures/{token}', [PublicClosureReportController::class, 'show'])->middleware('throttle:public-api');
Route::post('/public/closures/{token}/photos', [PublicClosureReportController::class, 'uploadPhoto'])->middleware('throttle:public-api');
Route::delete('/public/closures/{token}/photos/{photo}', [PublicClosureReportController::class, 'deletePhoto'])->middleware('throttle:public-api');
Route::get('/public/closures/{token}/photos/{photo}', [PublicClosureReportController::class, 'photo'])->middleware('throttle:public-api');
Route::post('/public/closures/{token}/submit', [PublicClosureReportController::class, 'submit'])->middleware('throttle:public-api');
Route::get('/public/renegotiations/{token}', [RenegotiationInvitationController::class, 'publicInfo'])->middleware('throttle:public-api');
Route::post('/public/renegotiations/{token}/proposal', [RenegotiationInvitationController::class, 'submit'])->middleware('throttle:public-api');

// Catálogos de referencia para el formulario público de propuesta de
// materiales (sin auth, consumidos por el enlace de invitación).
Route::get('/public/currencies', [CurrencyController::class, 'activePublicList'])->middleware('throttle:public-api');
Route::get('/public/catalog-categories', [CatalogCategoryController::class, 'publicList'])->middleware('throttle:public-api');
Route::get('/public/catalog-products/search', [CatalogProductController::class, 'publicSearch'])->middleware('throttle:public-api');

    
Route::middleware(['auth:sanctum', 'refresh.token'])->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::get('/auth/permissions', [AuthController::class, 'permissions']);
    Route::get('/auth/tabs', [AuthController::class, 'tabs']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Push notifications
    Route::post('/push-tokens', [PushTokenController::class, 'store']);
    Route::delete('/push-tokens', [PushTokenController::class, 'destroy']);

    // Bandeja de alertas internas persistentes
    Route::get('/notifications', [AppNotificationController::class, 'index']);
    Route::get('/notifications/unread-count', [AppNotificationController::class, 'unreadCount']);
    Route::patch('/notifications/{notification}/read', [AppNotificationController::class, 'markRead']);
    Route::patch('/notifications/read-all', [AppNotificationController::class, 'markAllRead']);
    Route::delete('/notifications/{notification}', [AppNotificationController::class, 'destroy']);
    Route::delete('/notifications', [AppNotificationController::class, 'destroyAll']);

    // CONFIG APP — lectura abierta a cualquier autenticado (varias features
    // consumen settings), edición restringida a administración. Las lecturas
    // van al bucket `catalog` (200/min, mismo patrón que /contractors,
    // /materials, /audit-logs más abajo): son GETs de solo consulta, y las
    // vistas de configuración (varios paneles montan 4-10 de estos por
    // navegación) no deben competir por el mismo presupuesto de 180/min que
    // el polling de fondo y las acciones de escritura del resto de la app.
    Route::get('/settings', [AppSettingController::class, 'index'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/settings/notification-actions', [AppSettingController::class, 'notificationActions'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    // Escritura de settings — exclusivo SUPERADMIN: incluye cron de IA/tasas,
    // datos fiscales y umbrales de presupuesto, impacto global del sistema.
    Route::patch('/settings/{setting}', [AppSettingController::class, 'update'])
        ->middleware('role:SUPERADMIN');

    // Historial de cambios de CONFIG APP — exclusivo de SUPERADMIN, separado
    // de /audit-logs (visible para cualquier autenticado, incl. Presidencia).
    Route::get('/config-audit-logs', [ConfigAuditLogController::class, 'index'])
        ->middleware(['role:SUPERADMIN'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/config-audit-logs/export', [ConfigAuditLogController::class, 'export'])
        ->middleware(['role:SUPERADMIN'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');

    // Matriz configurable rol × acción × canal — exclusivo SUPERADMIN. `action`
    // va en el body (no como path param) porque varias acciones del catálogo
    // contienen espacios y hasta una barra literal (ej. "Carga de hojas de
    // calculo/cubicaciones"), lo que haría frágil cualquier URL-encoding.
    Route::get('/notification-rules', [NotificationRuleController::class, 'index'])
        ->middleware(['role:SUPERADMIN'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::put('/notification-rules', [NotificationRuleController::class, 'update'])
        ->middleware('role:SUPERADMIN');

    // Control de IA por departamento/acción (Config IA) — lectura abierta a
    // cualquier autenticado, a diferencia de /notification-rules: cada rol
    // necesita saber si su propia IA está apagada para ocultar el botón
    // correspondiente, no solo SUPERADMIN viendo el panel de administración.
    // La escritura sigue siendo exclusiva SUPERADMIN.
    Route::get('/ai/feature-toggles', [AiFeatureToggleController::class, 'index'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::put('/ai/feature-toggles', [AiFeatureToggleController::class, 'update'])
        ->middleware('role:SUPERADMIN');

    // Moneda base vigente — abierto a cualquier autenticado (el Catálogo
    // Maestro y otros paneles internos la necesitan para mostrar montos
    // convertidos, no solo SUPERADMIN). Va ANTES del /currencies genérico
    // para no colisionar con una futura ruta {currency} de tipo string.
    Route::get('/currencies/base', [CurrencyController::class, 'base'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');

    // Catálogo de monedas aceptadas — exclusivo SUPERADMIN.
    Route::get('/currencies', [CurrencyController::class, 'index'])
        ->middleware(['role:SUPERADMIN'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::post('/currencies', [CurrencyController::class, 'store'])
        ->middleware('role:SUPERADMIN');
    Route::patch('/currencies/{currency}', [CurrencyController::class, 'update'])
        ->middleware('role:SUPERADMIN');
    Route::post('/currencies/{currency}/set-base', [CurrencyController::class, 'setBase'])
        ->middleware('role:SUPERADMIN');
    Route::delete('/currencies/{currency}', [CurrencyController::class, 'destroy'])
        ->middleware('role:SUPERADMIN');

    // Histórico de tasas de cambio a USD — exclusivo SUPERADMIN.
    Route::get('/exchange-rates', [ExchangeRateController::class, 'index'])
        ->middleware(['role:SUPERADMIN'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/exchange-rates/{currencyCode}/history', [ExchangeRateController::class, 'history'])
        ->middleware(['role:SUPERADMIN'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::post('/exchange-rates', [ExchangeRateController::class, 'store'])
        ->middleware('role:SUPERADMIN');
    Route::post('/exchange-rates/sync', [ExchangeRateController::class, 'sync'])
        ->middleware('role:SUPERADMIN');
    Route::get('/exchange-rates/sync-logs', [ExchangeRateController::class, 'syncLogs'])
        ->middleware('role:SUPERADMIN');
    Route::get('/exchange-rates/last-sync', [ExchangeRateController::class, 'lastSync'])
        ->middleware('role:SUPERADMIN');

    // Categorías del catálogo maestro de productos — exclusivo SUPERADMIN.
    Route::get('/catalog-categories', [CatalogCategoryController::class, 'index'])
        ->middleware(['role:SUPERADMIN'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::post('/catalog-categories', [CatalogCategoryController::class, 'store'])
        ->middleware('role:SUPERADMIN');
    Route::patch('/catalog-categories/{catalogCategory}', [CatalogCategoryController::class, 'update'])
        ->middleware('role:SUPERADMIN');
    Route::delete('/catalog-categories/{catalogCategory}', [CatalogCategoryController::class, 'destroy'])
        ->middleware('role:SUPERADMIN');

    // Reclasificación de productos personalizados pendientes — panel de Presidencia.
    Route::get('/custom-product-resolutions/pending', [CustomProductResolutionController::class, 'pending'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::post('/supplier-material-proposal-lines/{line}/resolve-product', [CustomProductResolutionController::class, 'store'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN');

    // Catálogo maestro consultable — submódulo de Presidencia (solo lectura).
    Route::get('/catalog/products', [CatalogProductController::class, 'index'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/catalog/products/{catalogProduct}', [CatalogProductController::class, 'show'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/catalog/products/{catalogProduct}/price-history', [CatalogProductController::class, 'priceHistory'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');

    Route::get('/modules', [ModuleController::class, 'index'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/contractors', [ContractorController::class, 'activeList'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::post('/contractors/{contractor}/rating', [ContractorController::class, 'updateRating']);
    Route::get('/contractors/{contractor}/history', [ContractorController::class, 'history'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/materials', [MaterialController::class, 'activeList'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/project-types', [ProjectTypeController::class, 'activeList'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])
        ->middleware('role:PRESIDENCIA,SUPERADMIN')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/audit-logs/export', [AuditLogController::class, 'export'])
        ->middleware('role:PRESIDENCIA,SUPERADMIN')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/audit-logs/summary', [AuditLogController::class, 'summary'])
        ->middleware('role:PRESIDENCIA,SUPERADMIN')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::post('/supplier-invitations', [SupplierInvitationController::class, 'store']);
    Route::get('/supplier-invitations/latest', [SupplierInvitationController::class, 'latest'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/supplier-material-proposals', [SupplierProposalController::class, 'index']);
    Route::get('/supplier-proposal-images/{token}/{path}', [SupplierProposalController::class, 'internalImage'])
        ->where('path', '.*')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');

    // Histórico de Obras (Presidencia): cadena obra → cierre con estimado vs aprobado vs ejecutado
    Route::get('/project-history', [\App\Http\Controllers\Api\ProjectHistoryController::class, 'index'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/project-history/export', [\App\Http\Controllers\Api\ProjectHistoryController::class, 'export'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/project-history/{project}', [\App\Http\Controllers\Api\ProjectHistoryController::class, 'show'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN')
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');

    // Resumen ejecutivo del dashboard de Presidencia (agregados exactos, sin paginación)
    Route::get('/dashboard/summary', DashboardSummaryController::class)
        ->middleware('role:PRESIDENCIA,SUPERADMIN');

    // Marketing — flujo de creación/aprobación de piezas publicitarias
    // (impresiones, viniles, pendones), separado del flujo de obra.
    Route::middleware('role:MARKETING,ADMIN,SUPERADMIN')->group(function () {
        Route::get('/marketing-projects', [MarketingProjectController::class, 'index'])
            ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
        Route::post('/marketing-projects', [MarketingProjectController::class, 'store']);
        Route::get('/marketing-projects/{marketingProject}', [MarketingProjectController::class, 'show'])
            ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
        Route::patch('/marketing-projects/{marketingProject}', [MarketingProjectController::class, 'update']);
        Route::delete('/marketing-projects/{marketingProject}', [MarketingProjectController::class, 'destroy']);
        Route::post('/marketing-projects/{marketingProject}/submit', [MarketingProjectController::class, 'submit']);

        Route::get('/marketing-projects/{marketingProject}/attachments', [MarketingProjectAttachmentController::class, 'index'])
            ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
        Route::post('/marketing-projects/{marketingProject}/attachments', [MarketingProjectAttachmentController::class, 'upload']);
        Route::delete('/marketing-projects/{marketingProject}/attachments/{attachment}', [MarketingProjectAttachmentController::class, 'destroy']);
        Route::get('/marketing-projects/{marketingProject}/attachments/{attachment}/download', [MarketingProjectAttachmentController::class, 'download'])
            ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
        Route::get('/marketing-projects/{marketingProject}/attachments/{attachment}/preview', [MarketingProjectAttachmentController::class, 'preview'])
            ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    });

    // Aprobación/rechazo — reservado a administración, separado del rol
    // MARKETING que solo crea/edita/envía a revisión.
    Route::post('/marketing-projects/{marketingProject}/approve', [MarketingProjectController::class, 'approve'])
        ->middleware('role:ADMIN,SUPERADMIN');
    Route::post('/marketing-projects/{marketingProject}/reject', [MarketingProjectController::class, 'reject'])
        ->middleware('role:ADMIN,SUPERADMIN');

    Route::apiResource('projects', ProjectController::class)->only(['index', 'store', 'show']);

    // Rutas protegidas por rol (matriz de permisos auditoría)
    Route::post('/projects/{project}/review', [ProjectController::class, 'review'])
        ->middleware('role:AUDITORIA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/evaluate-dossier', [ProjectController::class, 'evaluateDossier'])
        ->middleware('role:AUDITORIA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/reject-project', [ProjectController::class, 'rejectProject'])
        ->middleware('role:AUDITORIA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/resubmit', [ProjectController::class, 'resubmitProject'])
        ->middleware('role:INFRAESTRUCTURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/approve-investment', [ProjectController::class, 'approveInvestment'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/send-to-reevaluation', [ProjectController::class, 'sendToReevaluation'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/resolve-reevaluation', [ProjectController::class, 'resolveReevaluation'])
        ->middleware('role:AUDITORIA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/proposals', [ProjectController::class, 'addProposal'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::delete('/projects/{project}/proposals/{proposal}', [ProjectController::class, 'removeProposal'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/proposals/{proposal}/renegotiate', [ProjectController::class, 'renegotiateProposal'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/proposals/{proposal}/renegotiation-invite', [RenegotiationInvitationController::class, 'store'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/submit-comparative', [ProjectController::class, 'submitComparative'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/import-supplier-proposals', [ProjectController::class, 'importSupplierProposals'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/reject-proposals', [ProjectController::class, 'rejectProposals'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/select-contractor', [ProjectController::class, 'selectContractor'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');
    Route::post('/projects/award-approvals/batch', [AwardApprovalController::class, 'approveBatch'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/award-approval', [AwardApprovalController::class, 'approve'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/award-rejection', [AwardApprovalController::class, 'reject'])
        ->middleware('role:PRESIDENCIA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/send-to-finance', [AwardApprovalController::class, 'sendToFinance'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/payments', [ProjectController::class, 'pay'])
        ->middleware('role:FINANZAS,ADMIN,SUPERADMIN');
    Route::get('/projects/{project}/rate-freezes', [ProjectRateFreezeController::class, 'index']);
    Route::post('/projects/{project}/rate-freezes', [ProjectRateFreezeController::class, 'store'])
        ->middleware('role:SUPERADMIN');
    // Cierre posterior a la ejecución (finiquito): residente → Auditoría → Procura → Finanzas
    Route::get('/residents', [ClosureReportController::class, 'residents'])
        ->middleware('role:INFRAESTRUCTURA,ADMIN,SUPERADMIN');
    Route::patch('/projects/{project}/resident', [ClosureReportController::class, 'assignResident'])
        ->middleware('role:INFRAESTRUCTURA,ADMIN,SUPERADMIN');
    Route::get('/projects/{project}/closure-report', [ClosureReportController::class, 'show']);
    Route::get('/projects/{project}/closure-report/photos/{photo}', [ClosureReportController::class, 'photo'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::post('/projects/{project}/closure-report/photos', [ClosureReportController::class, 'uploadPhoto'])
        ->middleware('role:INFRAESTRUCTURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/closure-report/resend-link', [ClosureReportController::class, 'resendLink'])
        ->middleware('role:INFRAESTRUCTURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/closure-report/resident-approval', [ClosureReportController::class, 'residentApproval'])
        ->middleware('role:INFRAESTRUCTURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/closure-report/rejection', [ClosureReportController::class, 'reject'])
        ->middleware('role:INFRAESTRUCTURA,AUDITORIA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/closure-report/audit-approval', [ClosureReportController::class, 'auditApproval'])
        ->middleware('role:AUDITORIA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/closure-report/finiquito-request', [ClosureReportController::class, 'requestFiniquito'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/closure-report/finiquito-return', [ClosureReportController::class, 'returnToAudit'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');

    // AI Evaluation — mismo endpoint sirve a Procura (evaluación oficial del
    // cuadro comparativo) y a Analistas (vista previa antes de enviar a
    // Procura); el departamento para el gate de AiFeatureGate se resuelve
    // dentro del controller según el rol del usuario autenticado.
    Route::post('/ai/evaluate-proposals', [AIEvaluationController::class, 'evaluate'])
        ->middleware('role:PROCURA,ANALISTA,ADMIN,SUPERADMIN');
    Route::get('/ai/evaluate-proposals/status/{project}', [AIEvaluationController::class, 'status'])
        ->middleware('role:PROCURA,ANALISTA,ADMIN,SUPERADMIN');

    // Sugerencia IA de rating de proveedor — informativa, no autoritativa.
    // Mismos roles que ya administran el catálogo de proveedores
    // (/contractors/config más abajo, SUPERADMIN/ADMIN) — CATALOGOS es la
    // etiqueta de "departamento" en Roles::VALID/AiFeatureCatalog, no un rol
    // con sesión propia hoy.
    Route::get('/contractors/{contractor}/rating-suggestion', [ContractorController::class, 'ratingSuggestion'])
        ->middleware('role:ADMIN,SUPERADMIN');

    // Cronjob de RatingIA (batch, configurable por días) — mismos roles que
    // la sugerencia puntual de arriba.
    Route::post('/rating-ia/run', [RatingIaController::class, 'run'])
        ->middleware('role:ADMIN,SUPERADMIN');
    Route::get('/rating-ia/run-logs', [RatingIaController::class, 'runLogs'])
        ->middleware('role:ADMIN,SUPERADMIN');
    Route::get('/rating-ia/suggestions', [RatingIaController::class, 'suggestions'])
        ->middleware('role:ADMIN,SUPERADMIN');

    // Project documents (planos, hojas de cálculo, fotos, comprobantes de pago)
    Route::get('/projects/{project}/documents', [ProjectDocumentController::class, 'index'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::post('/projects/{project}/documents', [ProjectDocumentController::class, 'upload'])->middleware('role:INFRAESTRUCTURA,AUDITORIA,PROCURA,FINANZAS,ADMIN,SUPERADMIN');
    Route::delete('/projects/{project}/documents/{document}', [ProjectDocumentController::class, 'destroy'])->middleware('role:INFRAESTRUCTURA,AUDITORIA,ADMIN,SUPERADMIN');
    Route::get('/projects/{project}/documents/{document}/download', [ProjectDocumentController::class, 'download'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/projects/{project}/documents/{document}/preview', [ProjectDocumentController::class, 'preview'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/projects/{project}/documents/{document}/history', [ProjectDocumentController::class, 'history'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');

    // Gestión de usuarios, roles y permisos de acceso — exclusivo SUPERADMIN:
    // un ADMIN no debe poder asignarse (ni asignarle a otro) el rol SUPERADMIN
    // ni controlar qué vistas puede ver cada usuario (role_view_access).
    Route::middleware('role:SUPERADMIN')->group(function () {
        Route::get('/roles', [UserController::class, 'roles']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::post('/users/{user}/send-reset-link', [UserController::class, 'sendResetLink']);
        Route::get('/users/{user}/access', [AccessAdminController::class, 'show']);
        Route::put('/users/{user}/access', [AccessAdminController::class, 'update']);

        // Catálogo de roles (administración) — SUPERADMIN igual que Usuarios,
        // ya que agregar/quitar un rol afecta el alcance de todos los demás.
        Route::get('/roles/config', [RoleController::class, 'index']);
        Route::post('/roles/config', [RoleController::class, 'store']);
        Route::patch('/roles/config/{role}', [RoleController::class, 'update']);
        Route::post('/roles/config/{role}/toggle-status', [RoleController::class, 'toggleStatus']);

        // Catálogo de acciones notificables (metadatos) — SUPERADMIN, ya que
        // afecta la matriz de notificaciones completa. Sin ruta de alta (ver
        // NotificationActionController).
        Route::get('/notification-actions/config', [NotificationActionController::class, 'index']);
        Route::patch('/notification-actions/config/{notificationAction}', [NotificationActionController::class, 'update']);
        Route::post('/notification-actions/config/{notificationAction}/toggle-status', [NotificationActionController::class, 'toggleStatus']);
    });

    Route::middleware('role:SUPERADMIN,ADMIN')->group(function () {
        // Contractor / Proveedores configuration
        Route::get('/contractors/config', [ContractorController::class, 'index']);
        Route::post('/contractors/config', [ContractorController::class, 'store']);
        Route::get('/contractors/config/{contractor}', [ContractorController::class, 'show']);
        Route::patch('/contractors/config/{contractor}', [ContractorController::class, 'update']);
        Route::post('/contractors/config/{contractor}/toggle-status', [ContractorController::class, 'toggleStatus']);

        // Materials catalog configuration
        Route::get('/materials/config', [MaterialController::class, 'index']);
        Route::post('/materials/config', [MaterialController::class, 'store']);
        Route::get('/materials/config/{material}', [MaterialController::class, 'show']);
        Route::patch('/materials/config/{material}', [MaterialController::class, 'update']);
        Route::post('/materials/config/{material}/toggle-status', [MaterialController::class, 'toggleStatus']);

        // Project types configuration
        Route::get('/project-types/config', [ProjectTypeController::class, 'index']);
        Route::post('/project-types/config', [ProjectTypeController::class, 'store']);
        Route::patch('/project-types/config/{projectType}', [ProjectTypeController::class, 'update']);
        Route::post('/project-types/config/{projectType}/toggle-status', [ProjectTypeController::class, 'toggleStatus']);

        // AI Configuration (static routes BEFORE wildcard {id}) — GETs de
        // solo lectura al bucket `catalog` (mismo criterio que /settings,
        // /currencies, /notification-rules más arriba).
        Route::prefix('ai/config')->group(function () {
            Route::get('/usage', [AiConfigController::class, 'usage'])
                ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
            Route::get('/models', [AiConfigController::class, 'availableModels'])
                ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
            Route::get('/', [AiConfigController::class, 'index'])
                ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
            Route::get('/{aiConfig}', [AiConfigController::class, 'show'])
                ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
        });
    });

    // Credenciales de proveedores de IA (API keys) — mismo nivel de sensibilidad
    // que /system-keys (ya SUPERADMIN exclusivo): un ADMIN no debe poder
    // crear/editar/borrar ni disparar el test de conexión saliente.
    Route::middleware('role:SUPERADMIN')->prefix('ai/config')->group(function () {
        Route::post('/sync', [AiConfigController::class, 'sync']);
        Route::post('/', [AiConfigController::class, 'store']);
        Route::patch('/{aiConfig}', [AiConfigController::class, 'update']);
        Route::delete('/{aiConfig}', [AiConfigController::class, 'destroy']);
        Route::post('/{aiConfig}/test', [AiConfigController::class, 'test']);
    });

    // Configuración de Keys (SMTP, Pusher, Storage S3/local) — más sensible que el resto de
    // CONFIG APP (credenciales de infraestructura), por eso SUPERADMIN
    // exclusivo en vez de compartir el bucket SUPERADMIN,ADMIN de arriba.
    Route::middleware('role:SUPERADMIN')->prefix('system-keys')->group(function () {
        Route::get('/', [SystemKeyConfigController::class, 'index']);
        Route::patch('/{group}', [SystemKeyConfigController::class, 'update']);
        Route::post('/{group}/test', [SystemKeyConfigController::class, 'test']);
    });
});
