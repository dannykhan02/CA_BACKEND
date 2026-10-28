<?php

namespace Tests\Feature;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** The credit maintenance jobs are scheduled hourly, never overlap, and a dedicated scheduler role exists to run them. */
class CreditsSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_credit_jobs_are_hourly_non_overlapping_and_take_a_cache_lock(): void
    {
        $this->artisan('schedule:list')->assertSuccessful(); // loads routes/console.php
        $events = collect(app(Schedule::class)->events());

        foreach (['credits:expire-trials', 'credits:reconcile-attempts', 'billing:release-stale-reservations'] as $command) {
            $event = $events->first(fn ($e) => str_contains((string) $e->command, $command));
            self::assertNotNull($event, $command.' is scheduled');
            self::assertSame('0 * * * *', $event->expression, $command);
            self::assertTrue($event->withoutOverlapping, $command.' must not overlap');
            self::assertTrue($event->onOneServer, $command.' takes a lock so two schedulers cannot both run it');
        }
        self::assertStringContainsString('--apply', (string) $events->first(fn ($e) => str_contains((string) $e->command, 'credits:reconcile-attempts'))->command);
    }

    public function test_the_start_script_has_a_scheduler_role_and_leaves_web_and_worker_alone(): void
    {
        $script = file_get_contents(base_path('bin/start-production.sh'));

        self::assertStringContainsString('web|worker|scheduler', $script);
        self::assertStringContainsString('exec php artisan schedule:work', $script);
        self::assertStringContainsString('web) exec frankenphp run --config /etc/caddy/Caddyfile --adapter caddyfile ;;', $script);
        // The worker still execs Horizon; the in-worker scheduler is opt-in via WORKER_RUNS_SCHEDULER=1.
        self::assertMatchesRegularExpression('/worker\)\n(?:.*\n)*?\s*exec php artisan horizon ;;/', $script);
        self::assertStringContainsString('if [ "${WORKER_RUNS_SCHEDULER:-0}" = "1" ]; then', $script);
        // The scheduler branch ends before the ClamAV startup checks.
        self::assertLessThan(strpos($script, 'freshclam'), strpos($script, 'schedule:work'));
    }
}
