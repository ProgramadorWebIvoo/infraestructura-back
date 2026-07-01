<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\ProjectDocumentController;
use App\Http\Controllers\Api\SupportController;
use App\Http\Controllers\Api\UserController;

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

Route::post('/login', [AuthController::class, 'login']);
Route::post('/contractors', [SupportController::class, 'storeContractor']);
Route::get('/public/invitations/{token}', [SupportController::class, 'getInvitationPublicInfo']);
Route::post('/public/invitations/{token}/proposal', [SupportController::class, 'storeSupplierMaterialProposal']);

    
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/modules', [SupportController::class, 'modules'])->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class]);
    Route::get('/contractors', [SupportController::class, 'contractors'])->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class]);
    Route::post('/contractors/{contractor}/rating', [SupportController::class, 'updateContractorRating']);
    Route::get('/materials', [SupportController::class, 'materials'])->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class]);
    Route::get('/audit-logs', [SupportController::class, 'auditLogs'])->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class]);
    Route::post('/supplier-invitations', [SupportController::class, 'createSupplierInvitation']);
    Route::get('/supplier-material-proposals', [SupportController::class, 'supplierMaterialProposals']);

    Route::apiResource('projects', ProjectController::class)->only(['index', 'store', 'show']);
    Route::post('/projects/{project}/review', [ProjectController::class, 'review']);
    Route::post('/projects/{project}/approve-investment', [ProjectController::class, 'approveInvestment']);
    Route::post('/projects/{project}/proposals', [ProjectController::class, 'addProposal']);
    Route::delete('/projects/{project}/proposals/{proposal}', [ProjectController::class, 'removeProposal']);
    Route::post('/projects/{project}/submit-comparative', [ProjectController::class, 'submitComparative']);
    Route::post('/projects/{project}/reject-proposals', [ProjectController::class, 'rejectProposals']);
    Route::post('/projects/{project}/select-contractor', [ProjectController::class, 'selectContractor']);
    Route::post('/projects/{project}/payments', [ProjectController::class, 'pay']);
    Route::post('/projects/{project}/report-finished', [ProjectController::class, 'reportFinished']);

    
    Route::post('/projects/{project}/verify-completion', [ProjectController::class, 'verifyCompletion']);

    // Project documents (planos y hojas de cálculo)
    Route::get('/projects/{project}/documents', [ProjectDocumentController::class, 'index'])->withoutMiddleware([\Illuminate\Routing\Middleware\ThrottleRequests::class]);
    Route::post('/projects/{project}/documents', [ProjectDocumentController::class, 'upload']);
    Route::delete('/projects/{project}/documents/{document}', [ProjectDocumentController::class, 'destroy']);
    Route::get('/projects/{project}/documents/{document}/download', [ProjectDocumentController::class, 'download']);

    Route::middleware('role:SUPERADMIN,ADMIN')->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::post('/users', [UserController::class, 'store']);
    });
});
