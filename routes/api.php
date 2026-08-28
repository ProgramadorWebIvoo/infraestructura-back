<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuditLogController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ContractorController;
use App\Http\Controllers\Api\ModuleController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectDocumentController;
use App\Http\Controllers\Api\SupplierInvitationController;
use App\Http\Controllers\Api\SupplierProposalController;
use App\Http\Controllers\Api\UserController;
use App\Http\Controllers\Api\AIEvaluationController;
use App\Http\Controllers\Api\AiConfigController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\PushTokenController;
use App\Http\Controllers\Api\DashboardSummaryController;
use App\Http\Controllers\Api\AppNotificationController;
use App\Http\Controllers\Api\AppSettingController;
use App\Http\Controllers\Api\ConfigAuditLogController;
use App\Http\Controllers\Api\NotificationRuleController;
use App\Http\Controllers\Api\CurrencyController;
use App\Http\Controllers\Api\ExchangeRateController;
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

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:public-api');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->middleware('throttle:public-api');
Route::post('/contractors', [ContractorController::class, 'registerPublic'])->middleware('throttle:public-api');
Route::get('/public/invitations/{token}', [SupplierInvitationController::class, 'publicInfo'])->middleware('throttle:public-api');
Route::post('/public/invitations/{token}/proposal', [SupplierProposalController::class, 'store'])->middleware('throttle:public-api');
Route::post('/public/invitations/{token}/proposal-image', [SupplierProposalController::class, 'uploadImage'])->middleware('throttle:public-api');
Route::get('/public/invitations/{token}/proposal-image/{path}', [SupplierProposalController::class, 'image'])
    ->where('path', '.*')
    ->middleware('throttle:public-api');

// Catálogos de referencia para el formulario público de propuesta de
// materiales (sin auth, consumidos por el enlace de invitación).
Route::get('/public/currencies', [CurrencyController::class, 'activePublicList'])->middleware('throttle:public-api');
Route::get('/public/catalog-categories', [CatalogCategoryController::class, 'publicList'])->middleware('throttle:public-api');
Route::get('/public/catalog-products/search', [CatalogProductController::class, 'publicSearch'])->middleware('throttle:public-api');

    
Route::middleware(['auth:sanctum', 'refresh.token'])->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::get('/auth/permissions', [AuthController::class, 'permissions']);
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
    Route::patch('/settings/{setting}', [AppSettingController::class, 'update'])
        ->middleware('role:SUPERADMIN,ADMIN');

    // Historial de cambios de CONFIG APP — exclusivo de SUPERADMIN, separado
    // de /audit-logs (visible para cualquier autenticado, incl. Presidencia).
    Route::get('/config-audit-logs', [ConfigAuditLogController::class, 'index'])
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
    Route::get('/materials', [MaterialController::class, 'activeList'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::post('/supplier-invitations', [SupplierInvitationController::class, 'store']);
    Route::get('/supplier-invitations/latest', [SupplierInvitationController::class, 'latest'])
        ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/supplier-material-proposals', [SupplierProposalController::class, 'index']);

    // Resumen ejecutivo del dashboard de Presidencia (agregados exactos, sin paginación)
    Route::get('/dashboard/summary', DashboardSummaryController::class)
        ->middleware('role:PRESIDENCIA,SUPERADMIN');

    Route::apiResource('projects', ProjectController::class)->only(['index', 'store', 'show']);

    // Rutas protegidas por rol (matriz de permisos auditoría)
    Route::post('/projects/{project}/review', [ProjectController::class, 'review'])
        ->middleware('role:CIERRE_DE_OBRA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/evaluate-dossier', [ProjectController::class, 'evaluateDossier'])
        ->middleware('role:CIERRE_DE_OBRA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/reject-project', [ProjectController::class, 'rejectProject'])
        ->middleware('role:CIERRE_DE_OBRA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/resubmit', [ProjectController::class, 'resubmitProject'])
        ->middleware('role:INFRAESTRUCTURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/approve-investment', [ProjectController::class, 'approveInvestment'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/proposals', [ProjectController::class, 'addProposal'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::delete('/projects/{project}/proposals/{proposal}', [ProjectController::class, 'removeProposal'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/proposals/{proposal}/renegotiate', [ProjectController::class, 'renegotiateProposal'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/submit-comparative', [ProjectController::class, 'submitComparative'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/import-supplier-proposals', [ProjectController::class, 'importSupplierProposals'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/reject-proposals', [ProjectController::class, 'rejectProposals'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/select-contractor', [ProjectController::class, 'selectContractor'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/payments', [ProjectController::class, 'pay'])
        ->middleware('role:FINANZAS,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/report-finished', [ProjectController::class, 'reportFinished'])
        ->middleware('role:CIERRE_DE_OBRA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/verify-completion', [ProjectController::class, 'verifyCompletion'])
        ->middleware('role:CIERRE_DE_OBRA,ADMIN,SUPERADMIN');

    // AI Evaluation
    Route::post('/ai/evaluate-proposals', [AIEvaluationController::class, 'evaluate'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');

    // Project documents (planos, hojas de cálculo, fotos)
    Route::get('/projects/{project}/documents', [ProjectDocumentController::class, 'index'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::post('/projects/{project}/documents', [ProjectDocumentController::class, 'upload'])->middleware('role:INFRAESTRUCTURA,CIERRE_DE_OBRA,ADMIN,SUPERADMIN');
    Route::delete('/projects/{project}/documents/{document}', [ProjectDocumentController::class, 'destroy'])->middleware('role:INFRAESTRUCTURA,CIERRE_DE_OBRA,ADMIN,SUPERADMIN');
    Route::get('/projects/{project}/documents/{document}/download', [ProjectDocumentController::class, 'download'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/projects/{project}/documents/{document}/preview', [ProjectDocumentController::class, 'preview'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
    Route::get('/projects/{project}/documents/{document}/history', [ProjectDocumentController::class, 'history'])->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');

    Route::middleware('role:SUPERADMIN,ADMIN')->group(function () {
        Route::get('/roles', [UserController::class, 'roles']);
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
        Route::patch('/users/{user}', [UserController::class, 'update']);
        Route::post('/users/{user}/toggle-status', [UserController::class, 'toggleStatus']);
        Route::post('/users/{user}/send-reset-link', [UserController::class, 'sendResetLink']);

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

        // AI Configuration (static routes BEFORE wildcard {id}) — GETs de
        // solo lectura al bucket `catalog` (mismo criterio que /settings,
        // /currencies, /notification-rules más arriba); escrituras y el
        // endpoint `test` (que sí dispara una llamada saliente real al
        // proveedor de IA) se quedan en el bucket general.
        Route::prefix('ai/config')->group(function () {
            Route::get('/usage', [AiConfigController::class, 'usage'])
                ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
            Route::get('/models', [AiConfigController::class, 'availableModels'])
                ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
            Route::post('/sync', [AiConfigController::class, 'sync']);
            Route::get('/', [AiConfigController::class, 'index'])
                ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
            Route::post('/', [AiConfigController::class, 'store']);
            Route::get('/{aiConfig}', [AiConfigController::class, 'show'])
                ->withoutMiddleware(['throttle:api'])->middleware('throttle:catalog');
            Route::patch('/{aiConfig}', [AiConfigController::class, 'update']);
            Route::delete('/{aiConfig}', [AiConfigController::class, 'destroy']);
            Route::post('/{aiConfig}/test', [AiConfigController::class, 'test']);
        });
    });
});
