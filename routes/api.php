<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentApproveController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentDownloadController;
use App\Http\Controllers\Api\DocumentRejectController;
use App\Http\Controllers\Api\DocumentReprocessController;
use App\Http\Controllers\Api\DocumentSearchController; // <-- ensure this import exists
use App\Http\Controllers\Api\DocumentUploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    // ... (unchanged)
});

Route::middleware('auth:sanctum')->group(function () {

    // Documents
    Route::get('/documents', [DocumentController::class, 'index'])
        ->name('documents.index');

    Route::get('/documents/{document}', [DocumentController::class, 'show'])
        ->name('documents.show');

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

    Route::get('/documents/search', [DocumentSearchController::class, 'search'])
        ->name('documents.search');
});

// Administrator-only routes (unchanged)
Route::middleware(['auth:sanctum', 'role:Administrator'])
    ->prefix('admin')
    ->group(function () {
        Route::get('/users', [UserController::class, 'index']);
        Route::patch('/users/{user}/role', [UserController::class, 'updateRole']);
    });