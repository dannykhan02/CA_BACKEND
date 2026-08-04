<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Horizon;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        parent::boot();

        // Horizon::routeSmsNotificationsTo('15556667777');
        // Horizon::routeMailNotificationsTo('example@example.com');
        // Horizon::routeSlackNotificationsTo('slack-webhook-url', '#channel');
    }

    /**
     * Register the Horizon gate.
     *
     * This gate determines who can access Horizon in non-local environments.
     *
     * Previously a hardcoded empty array — safe by default (deny-all) but
     * meant nobody had legitimate dashboard access either (audit F-Medium-5).
     * Now driven by HORIZON_AUTHORIZED_EMAILS in .env, comma-separated.
     * Add to .env:
     *   HORIZON_AUTHORIZED_EMAILS=ops1@example.com,ops2@example.com
     */
    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user = null) {
            $authorized = array_filter(array_map(
                'trim',
                explode(',', (string) env('HORIZON_AUTHORIZED_EMAILS', ''))
            ));

            return in_array(optional($user)->email, $authorized, true);
        });
    }
}
