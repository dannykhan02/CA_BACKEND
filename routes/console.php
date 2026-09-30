<?php

use App\Models\Subscription;
use App\Models\VerificationCode;
use App\Services\EntitlementService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Prune Laravel Pulse's own telemetry data — pulse_entries is currently
// the largest table in the database (18 MB) and grows unbounded with no
// cleanup otherwise. Keeps the last 7 days, matching Pulse's own default
// retention recommendation.
Schedule::command('pulse:prune')->daily();

// Remove verification codes past their expiry — these serve no purpose
// once expired and accumulate indefinitely otherwise. VerificationCode
// does not use the Prunable trait, so this deletes directly rather than
// relying on model:prune, which would silently do nothing without it.
Schedule::call(function () {
    VerificationCode::where('expires_at', '<', now())->delete();
})->everyMinute();

// Clean up old failed_jobs entries older than 30 days — keeps recent
// failures visible for debugging without unbounded growth.
Schedule::command('queue:prune-failed', ['--hours' => 24 * 30])->daily();

// Entitlements also check dates on every request; this keeps reporting states current.
Schedule::call(function () {
    Subscription::whereIn('status', ['active', 'non_renewing', 'past_due'])->each(function ($subscription) {
        app(EntitlementService::class)->subscription($subscription->workspace_id);
    });
})->name('billing-expiration')->hourly()->withoutOverlapping();

// Billing events retry through Horizon. `billing:reconcile-events` is an
// operator-triggered fallback; no billing correctness schedule is required.
