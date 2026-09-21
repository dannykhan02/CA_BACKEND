<?php

use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\BillingController;
use App\Http\Controllers\Api\CreditPurchaseController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\DocumentApproveController;
use App\Http\Controllers\Api\DocumentComparisonController;
use App\Http\Controllers\Api\DocumentContextController;
use App\Http\Controllers\Api\DocumentController;
use App\Http\Controllers\Api\DocumentDownloadController;
use App\Http\Controllers\Api\DocumentIntelligenceController;
use App\Http\Controllers\Api\DocumentQaController;
use App\Http\Controllers\Api\DocumentRejectController;
use App\Http\Controllers\Api\DocumentRelationshipController;
use App\Http\Controllers\Api\DocumentReportController;
use App\Http\Controllers\Api\DocumentReprocessController;
use App\Http\Controllers\Api\DocumentRiskReviewController;
use App\Http\Controllers\Api\DocumentSearchController;
use App\Http\Controllers\Api\DocumentUploadController;
use App\Http\Controllers\Api\HealthController;
use App\Http\Controllers\Api\MatterController;
use App\Http\Controllers\Api\ReferralController;
use App\Http\Controllers\Api\TrackedItemController;
use App\Http\Controllers\Api\WorkspaceCreditController;
use App\Http\Controllers\Api\WorkspaceInsightsController;
use App\Http\Middleware\EnsureEmailIsVerified;
use Illuminate\Support\Facades\Route;

// Unauthenticated — must be reachable by load balancers/uptime monitors/
// deployment scripts without a Sanctum token.
Route::get('/billing/plans', [BillingController::class, 'plans']);

Route::get('/health', [HealthController::class, 'index'])->name('health');

// Paystack authenticates with HMAC-SHA512, not a Sanctum bearer token.
Route::post('/paystack/webhook', [CreditPurchaseController::class, 'webhook'])->name('paystack.webhook');

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

    // Deliberately left on plain auth:sanctum (no EnsureEmailIsVerified):
    // an unverified user still needs /me and /signout to function, and
    // change-email gives them a path to fix a mistyped address even
    // pre-verification.
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

// Audit AUTH-1: was plain 'auth:sanctum'.
Route::middleware(['auth:sanctum', EnsureEmailIsVerified::class])->group(function () {
    Route::patch('/user', [AuthController::class, 'updateProfile'])
        ->name('user.update');

    Route::patch('/user/password', [AuthController::class, 'updatePassword'])
        ->name('user.update-password');
    Route::patch('/user/notification-preferences', [AuthController::class, 'updateNotificationPreferences'])
        ->name('user.update-notification-preferences');
});

// Audit AUTH-1: was plain 'auth:sanctum'.
Route::middleware(['auth:sanctum', EnsureEmailIsVerified::class])->group(function () {

    // Documents
    Route::get('/referrals/my-code', [ReferralController::class, 'myCode'])->name('referrals.my-code');
    Route::get('/workspace/billing', [BillingController::class, 'show']);
    Route::post('/workspace/billing/cancel', [BillingController::class, 'cancel'])->middleware('throttle:10,1');
    Route::post('/workspace/billing/manage', [BillingController::class, 'manage'])->middleware('throttle:10,1');
    Route::get('/workspace/credits', [WorkspaceCreditController::class, 'show'])
        ->name('workspace.credits');
    Route::post('/workspace/credits/purchases', [CreditPurchaseController::class, 'store'])
        ->name('workspace.credits.purchases');
    Route::post('/workspace/credits/purchases/{reference}/verify', [CreditPurchaseController::class, 'verify'])
        ->middleware('throttle:30,1')->name('workspace.credits.purchases.verify');

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

    Route::get('/documents/{document}/intelligence', [DocumentIntelligenceController::class, 'show'])
        ->name('documents.intelligence');

    Route::get('/documents/{document}/entities', [DocumentIntelligenceController::class, 'entities'])
        ->name('documents.entities');

    Route::get('/documents/{document}/risks', [DocumentIntelligenceController::class, 'risks'])
        ->name('documents.risks');

    Route::get('/documents/{document}/deadlines', [DocumentIntelligenceController::class, 'deadlines'])
        ->name('documents.deadlines');

    Route::get('/documents/{document}/summary', [DocumentIntelligenceController::class, 'summary'])
        ->name('documents.summary');

    Route::get('/dashboard/summary', [DashboardController::class, 'summary'])
        ->name('dashboard.summary');

    Route::get('/workspace/insights/trends', [WorkspaceInsightsController::class, 'trends'])
        ->name('workspace.insights.trends');

    Route::post('/documents', [DocumentUploadController::class, 'store'])
        ->middleware('throttle:document-uploads')
        ->name('documents.store');

    Route::get('/documents/{document}/download', [DocumentDownloadController::class, 'show'])
        ->name('documents.download');

    Route::post('/documents/{document}/reprocess', [DocumentReprocessController::class, 'store'])
        ->name('documents.reprocess');
    Route::delete('/documents/{document}', [DocumentController::class, 'destroy'])
        ->name('documents.destroy');

    Route::post('/documents/{document}/approve', [DocumentApproveController::class, 'store'])
        ->name('documents.approve');

    Route::post('/documents/{document}/reject', [DocumentRejectController::class, 'store'])
        ->name('documents.reject');

    Route::post('/documents/{document}/report-generated', [DocumentReportController::class, 'store'])
        ->name('documents.report-generated');
});

// Administrator-only routes
Route::middleware(['auth:sanctum', 'role:Administrator'])
    ->prefix('admin')
    ->group(function () {

        Route::get('/users', [UserController::class, 'index']);

        Route::patch('/users/{user}/role', [UserController::class, 'updateRole']);
    });

Route::middleware(['auth:sanctum', EnsureEmailIsVerified::class])->group(function () {
    $matter = MatterController::class;
    Route::get('/matters', [$matter, 'index']);
    Route::post('/matters', [$matter, 'store']);
    Route::get('/matters/{matter}', [$matter, 'show']);
    Route::patch('/matters/{matter}', [$matter, 'update']);
    Route::delete('/matters/{matter}', [$matter, 'destroy']);
    Route::post('/matters/{matter}/report-generated', [$matter, 'reportGenerated']);
    Route::get('/matters/{matter}/intelligence', [$matter, 'intelligence']);
    Route::post('/matters/{matter}/documents/{document}', [$matter, 'assign']);
    Route::delete('/matters/{matter}/documents/{document}', [$matter, 'assign']);
    $relationships = DocumentRelationshipController::class;
    Route::get('/document-relationships', [$relationships, 'index']);
    Route::post('/document-relationships', [$relationships, 'store']);
    Route::patch('/document-relationships/{relationship}', [$relationships, 'update']);
    Route::delete('/document-relationships/{relationship}', [$relationships, 'destroy']);
    $tracked = TrackedItemController::class;
    Route::get('/tracked-items', [$tracked, 'index']);
    Route::post('/tracked-items', [$tracked, 'store']);
    Route::patch('/tracked-items/{trackedItem}', [$tracked, 'update']);
    $comparisons = DocumentComparisonController::class;
    Route::get('/document-comparisons', [$comparisons, 'index']);
    Route::post('/document-comparisons', [$comparisons, 'store'])->middleware('throttle:20,1');
    Route::get('/document-comparisons/{comparison}', [$comparisons, 'show']);
    Route::patch('/documents/{document}/risks/{risk}', [DocumentRiskReviewController::class, 'update']);
    Route::get('/documents/{document}/context', [DocumentContextController::class, 'show']);
    Route::post('/documents/{document}/suggestions/{related}/dismiss', [DocumentContextController::class, 'dismiss']);
});
