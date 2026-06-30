<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\SupportController;

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

    
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/user', [AuthController::class, 'me']);
    Route::post('/logout', [AuthController::class, 'logout']);

    Route::get('/modules', [SupportController::class, 'modules']);
    Route::get('/contractors', [SupportController::class, 'contractors']);
    Route::patch('/contractors/{contractor}/rating', [SupportController::class, 'updateContractorRating']);
    Route::get('/materials', [SupportController::class, 'materials']);
    Route::get('/audit-logs', [SupportController::class, 'auditLogs']);

    Route::apiResource('projects', ProjectController::class)->only(['index', 'store', 'show']);
    Route::post('/projects/{project}/review', [ProjectController::class, 'review']);
    Route::post('/projects/{project}/approve-investment', [ProjectController::class, 'approveInvestment']);
    Route::post('/projects/{project}/proposals', [ProjectController::class, 'addProposal']);
    Route::delete('/projects/{project}/proposals/{proposal}', [ProjectController::class, 'removeProposal']);
    Route::post('/projects/{project}/submit-comparative', [ProjectController::class, 'submitComparative']);
    Route::post('/projects/{project}/select-contractor', [ProjectController::class, 'selectContractor']);
    Route::post('/projects/{project}/payments', [ProjectController::class, 'pay']);
    Route::post('/projects/{project}/report-finished', [ProjectController::class, 'reportFinished']);
    Route::post('/projects/{project}/verify-completion', [ProjectController::class, 'verifyCompletion']);
});
