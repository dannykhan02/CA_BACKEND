<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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
    \Illuminate\Support\Facades\Log::info('SCHEDULER TEST: verification code cleanup ran at ' . now());
    \App\Models\VerificationCode::where('expires_at', '<', now())->delete();
})->everyMinute();

// Clean up old failed_jobs entries older than 30 days — keeps recent
// failures visible for debugging without unbounded growth.
Schedule::command('queue:prune-failed', ['--hours' => 24 * 30])->daily();

Schedule::call(function () {
    \App\Models\TrackedItem::where('status', 'open')->whereNotNull('remind_at')
        ->where('remind_at', '<=', now())->whereNull('reminded_at')
        ->chunkById(100, function ($items) {
            foreach ($items as $item) \App\Jobs\SendTrackedDeadlineReminder::dispatch($item->id);
        });
})->name('tracked-deadline-reminders')->everyMinute()->withoutOverlapping();
