<?php

namespace App\Providers;

use App\Models\Document;
use App\Models\Workspace;
use App\Observers\DocumentObserver;
use App\Observers\WorkspaceObserver;
use App\Services\AI\Incremental\EvidenceGrounding;
use App\Services\AI\ProviderGate;
use App\Services\Ocr\OcrEngineResolver;
use App\Support\QueueTopology;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One gate per process: it tracks the permit a job is already holding.
        $this->app->singleton(ProviderGate::class);

        // One span set per process: a document's evidence spans are segmented once per
        // extraction version and reused by every chunk, validation and merge in the same worker.
        $this->app->singleton(EvidenceGrounding::class);

        // OCR provider list is config-driven (config/ocr.php) — adding
        // a provider means adding one line there, not touching this
        // binding, ExtractDocumentTextJob, or anything else that consumes OCR.
        $this->app->singleton(OcrEngineResolver::class, function ($app) {
            return new OcrEngineResolver(
                $app,
                config('ocr.providers'),
                config('ocr.default_provider'),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Safety net: a dispatch without an explicit onQueue() still reaches its pool.
        Queue::route(QueueTopology::ROUTES);

        if ($address = config('mail.reply_to.address')) {
            Mail::alwaysReplyTo($address, config('mail.reply_to.name'));
        }

        if (env('APP_DEMO_MODE') === '1') {
            $database = config('database.connections.pgsql');
            if (config('database.default') !== 'pgsql'
                || ($database['host'] ?? null) !== '127.0.0.1'
                || ($database['database'] ?? null) !== 'docintel_demo'
                || ($database['url'] ?? null)) {
                throw new \RuntimeException('Demo mode requires the local docintel_demo database.');
            }

            config()->set('filesystems.disks.documents', [
                'driver' => 'local',
                'root' => base_path('../demo/storage/documents'),
                'visibility' => 'private',
                'throw' => true,
            ]);
        }

        Document::observe(DocumentObserver::class);
        Workspace::observe(WorkspaceObserver::class);

        // Day 5 — protects against a single account (or compromised token)
        // hammering the upload endpoint and running up Anthropic API costs.
        // Separate concern from AnthropicClient's own per-minute throttle,
        // which protects Anthropic's rate limits specifically.
        RateLimiter::for('document-uploads', function ($request) {
            return Limit::perHour(20)->by($request->user()?->id ?: $request->ip());
        });

        // Consolidation refactor — protects signup against automated
        // account-creation spam, keyed by IP since there's no user yet.
        RateLimiter::for('signup', function ($request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Production monitoring (Item 8) -- Pulse's own default gate
        // restricts the dashboard to the local environment only. Reusing
        // HORIZON_AUTHORIZED_EMAILS rather than a separate Pulse-specific
        // env var: same ops audience already maintains that list for
        // Horizon, and Pulse is the same category of internal
        // operational dashboard, not end-user-facing.
        Gate::define('viewPulse', function ($user = null) {
            $authorized = array_filter(array_map(
                'trim',
                explode(',', (string) config('horizon.authorized_emails', ''))
            ));

            return in_array(optional($user)->email, $authorized, true);
        });
    }
}
