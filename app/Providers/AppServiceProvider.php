<?php

namespace App\Providers;

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
        //
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
    }
}