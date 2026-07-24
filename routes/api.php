<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentDownloadController;
use App\Http\Controllers\Api\DocumentReprocessController;
use App\Http\Controllers\Api\DocumentUploadController;
use App\Http\Controllers\Api\DashboardController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->name('auth.')->group(function () {
    Route::post('/signup', [AuthController::class, 'signup'])->name('signup');
    Route::post('/signin', [AuthController::class, 'signin'])->name('signin');
    Route::post('/verify-email', [AuthController::class, 'verifyEmail'])->name('verify-email');
    Route::post('/resend-verification', [AuthController::class, 'resendVerification'])->name('resend-verification');
    Route::post('/forgot-password', [AuthController::class, 'forgotPassword'])->name('forgot-password');
    Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('reset-password');

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('/signout', [AuthController::class, 'signout'])->name('signout');
    });
});

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    // Day 4 — Documents CRUD (list, detail, dashboard summary).
    // Day 6 will layer Policy-based role gating on top of these same routes.
    Route::get('/documents', [DocumentController::class, 'index'])->name('documents.index');
    Route::get('/documents/{document}', [DocumentController::class, 'show'])->name('documents.show');
    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])->name('dashboard.summary');

    // Day 5 — Document upload + async processing pipeline (scan → extract → insights).
    // Per-user hourly throttle here is separate from AnthropicClient's own
    // internal per-minute rate limiter — this one protects the endpoint itself.
    Route::post('/documents', [DocumentUploadController::class, 'store'])
        ->middleware('throttle:document-uploads')
        ->name('documents.store');

    // Day 5 — Document retrieval and manual reprocessing.
    Route::get('/documents/{document}/download', [DocumentDownloadController::class, 'show'])
        ->name('documents.download');

    Route::post('/documents/{document}/reprocess', [DocumentReprocessController::class, 'store'])
        ->name('documents.reprocess');
});