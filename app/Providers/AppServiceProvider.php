<?php

namespace App\Providers;

use App\Services\Ocr\ClaudeVisionOcrProvider;
use App\Services\Ocr\OcrEngineResolver;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // OCR provider list, in resolution order — the single place that
        // knows which providers exist and which one wins for a given
        // document. Adding a Tesseract or Azure Document Intelligence
        // provider later means adding one line here, not touching
        // ExtractDocumentTextJob or anything else that consumes OCR.
        $this->app->singleton(OcrEngineResolver::class, function ($app) {
            return new OcrEngineResolver([
                $app->make(ClaudeVisionOcrProvider::class),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
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
    }
}