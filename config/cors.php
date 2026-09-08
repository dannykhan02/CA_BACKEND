<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // FRONTEND_URL is the single canonical URL — also used to build
    // password-reset/email-change links (see User::sendPasswordResetNotification()),
    // so it must stay one clean URL, never a comma-joined list.
    // ADDITIONAL_CORS_ORIGINS is a comma-separated list of any other origins
    // (e.g. localhost during local dev) that should also be allowed to make
    // cross-origin requests, without touching what email links point to.
    // array_filter() drops empty entries so an unset ADDITIONAL_CORS_ORIGINS
    // in production doesn't add a blank origin to the list.
    'allowed_origins' => array_values(array_filter(array_merge(
        [env('FRONTEND_URL', 'http://localhost:5173')],
        array_map('trim', explode(',', env('ADDITIONAL_CORS_ORIGINS', '')))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];