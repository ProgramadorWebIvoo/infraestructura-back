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

    
Route::middleware(['auth:sanctum', 'refresh.token'])->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::get('/auth/permissions', [AuthController::class, 'permissions']);
    Route::post('/logout', [AuthController::class, 'logout']);

    // Push notifications
    Route::post('/push-tokens', [PushTokenController::class, 'store']);
    Route::delete('/push-tokens', [PushTokenController::class, 'destroy']);

    Route::get('/modules', [ModuleController::class, 'index'])->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class])->middleware('throttle:catalog');
    Route::get('/contractors', [ContractorController::class, 'activeList'])->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class])->middleware('throttle:catalog');
    Route::post('/contractors/{contractor}/rating', [ContractorController::class, 'updateRating']);
    Route::get('/materials', [MaterialController::class, 'activeList'])->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class])->middleware('throttle:catalog');
    Route::get('/audit-logs', [AuditLogController::class, 'index'])->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class])->middleware('throttle:catalog');
    Route::post('/supplier-invitations', [SupplierInvitationController::class, 'store']);
    Route::get('/supplier-material-proposals', [SupplierProposalController::class, 'index']);

    // Resumen ejecutivo del dashboard de Presidencia (agregados exactos, sin paginación)
    Route::get('/dashboard/summary', DashboardSummaryController::class)
        ->middleware('role:PRESIDENCIA,SUPERADMIN');

    Route::apiResource('projects', ProjectController::class)->only(['index', 'store', 'show']);

    // Rutas protegidas por rol (matriz de permisos auditoría)
    Route::post('/projects/{project}/review', [ProjectController::class, 'review'])
        ->middleware('role:CIERRE_DE_OBRA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/approve-investment', [ProjectController::class, 'approveInvestment'])
        ->middleware('role:PROCURA,ADMIN,SUPERADMIN');
    Route::post('/projects/{project}/proposals', [ProjectController::class, 'addProposal'])
        ->middleware('role:ANALISTA,ADMIN,SUPERADMIN');
    Route::delete('/projects/{project}/proposals/{proposal}', [ProjectController::class, 'removeProposal'])
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

    // Project documents (planos y hojas de cálculo)
    Route::get('/projects/{project}/documents', [ProjectDocumentController::class, 'index'])->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class])->middleware('throttle:catalog');
    Route::post('/projects/{project}/documents', [ProjectDocumentController::class, 'upload']);
    Route::delete('/projects/{project}/documents/{document}', [ProjectDocumentController::class, 'destroy']);
    Route::get('/projects/{project}/documents/{document}/download', [ProjectDocumentController::class, 'download']);

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

        // AI Configuration (static routes BEFORE wildcard {id})
        Route::prefix('ai/config')->group(function () {
            Route::get('/usage', [AiConfigController::class, 'usage']);
            Route::get('/models', [AiConfigController::class, 'availableModels']);
            Route::post('/sync', [AiConfigController::class, 'sync']);
            Route::get('/', [AiConfigController::class, 'index']);
            Route::post('/', [AiConfigController::class, 'store']);
            Route::get('/{id}', [AiConfigController::class, 'show']);
            Route::patch('/{id}', [AiConfigController::class, 'update']);
            Route::delete('/{id}', [AiConfigController::class, 'destroy']);
            Route::post('/{id}/test', [AiConfigController::class, 'test']);
        });
    });
});
