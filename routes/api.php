<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentApproveController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentDownloadController;
use App\Http\Controllers\Api\DocumentRejectController;
use App\Http\Controllers\Api\DocumentQaController;
use App\Http\Controllers\Api\DocumentReprocessController;
use App\Http\Controllers\Api\DocumentSearchController;
use App\Http\Controllers\Api\DocumentUploadController;
use App\Http\Controllers\Api\HealthController;
use Illuminate\Support\Facades\Route;

// Unauthenticated — must be reachable by load balancers/uptime monitors/
// deployment scripts without a Sanctum token.
Route::get('/health', [HealthController::class, 'index'])->name('health');

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/signup', [AuthController::class, 'signup'])
        ->middleware('throttle:signup')
        ->name('signup');

    Route::post('/signin', [AuthController::class, 'signin'])
        ->name('signin');

    // Token-based Google Sign-In
    Route::post('/google', [AuthController::class, 'google'])
        ->name('google');

    Route::post('/verify-email', [AuthController::class, 'verifyEmail'])
        ->name('verify-email');

    Route::post('/resend-verification', [AuthController::class, 'resendVerification'])
        ->name('resend-verification');

    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])
        ->name('forgot-password');

    Route::post('/reset-password', [AuthController::class, 'resetPassword'])
        ->name('reset-password');

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('/me', [AuthController::class, 'me'])
            ->name('me');

        Route::post('/signout', [AuthController::class, 'signout'])
            ->name('signout');
        Route::post('/change-email/request', [AuthController::class, 'requestEmailChange'])
            ->name('change-email.request');
        Route::post('/change-email/confirm', [AuthController::class, 'confirmEmailChange'])
            ->name('change-email.confirm');
    });
});

Route::middleware('auth:sanctum')->group(function () {

    // Documents
    Route::get('/documents', [DocumentController::class, 'index'])
        ->name('documents.index');

    // ✅ IMPORTANT: The search route MUST come before /documents/{document}
    // to prevent "search" from being interpreted as a document ID.
    Route::get('/documents/search', [DocumentSearchController::class, 'search'])
        ->name('documents.search');

    // Day 9 — Document Q&A. Sits alongside search (not scoped to a single
    // document, no {document} route-model-binding param) — placement here
    // is arbitrary relative to /documents/{document} since 'query' isn't
    // 'search' and won't collide the same way, but kept next to search for
    // readability since both are read-only retrieval endpoints.
    Route::post('/documents/query', [DocumentQaController::class, 'ask'])
        ->name('documents.query');

    Route::get('/documents/{document}', [DocumentController::class, 'show'])
        ->name('documents.show');

    Route::get('/documents/{document}/intelligence', [\App\Http\Controllers\Api\DocumentIntelligenceController::class, 'show'])
        ->name('documents.intelligence');

    Route::get('/documents/{document}/entities', [\App\Http\Controllers\Api\DocumentIntelligenceController::class, 'entities'])
        ->name('documents.entities');

    Route::get('/documents/{document}/risks', [\App\Http\Controllers\Api\DocumentIntelligenceController::class, 'risks'])
        ->name('documents.risks');

    Route::get('/documents/{document}/deadlines', [\App\Http\Controllers\Api\DocumentIntelligenceController::class, 'deadlines'])
        ->name('documents.deadlines');

    Route::get('/documents/{document}/summary', [\App\Http\Controllers\Api\DocumentIntelligenceController::class, 'summary'])
        ->name('documents.summary');

    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
        ->name('dashboard.summary');

    Route::post('/documents', [DocumentUploadController::class, 'store'])
        ->middleware('throttle:document-uploads')
        ->name('documents.store');

    Route::get('/documents/{document}/download', [DocumentDownloadController::class, 'show'])
        ->name('documents.download');

    Route::post('/documents/{document}/reprocess', [DocumentReprocessController::class, 'store'])
        ->name('documents.reprocess');

    Route::post('/documents/{document}/approve', [DocumentApproveController::class, 'store'])
        ->name('documents.approve');

    Route::post('/documents/{document}/reject', [DocumentRejectController::class, 'store'])
        ->name('documents.reject');
});

// Administrator-only routes
Route::middleware(['auth:sanctum', 'role:Administrator'])
    ->prefix('admin')
    ->group(function () {

        Route::get('/users', [UserController::class, 'index']);

        Route::patch('/users/{user}/role', [UserController::class, 'updateRole']);
    });