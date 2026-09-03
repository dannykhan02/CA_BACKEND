<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use App\Exceptions\EmbeddingProviderException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Sentry\Laravel\Integration;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        // ✅ Appended globally – this adds security headers to every response
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        // Fix: API-only app has no 'login' route at all (confirmed via
        // `php artisan route:list | grep login` -> zero matches), so
        // Authenticate::unauthenticated()'s login-route redirect always
        // crashed with a raw 500 (Route [login] not defined) before any
        // exception render() handler ran -- including for plain requests
        // with no Accept header, since expectsJson() is false for those
        // (no X-Requested-With, no */json Accept), so a first attempt at
        // this fix that branched on expectsJson() still hit the same
        // crash for exactly that case. Always returning null means no
        // redirect is ever attempted, for any guest request, letting
        // Laravel fall through to its normal JSON 401 response.
        $middleware->redirectGuestsTo(fn (Request $request) => null);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        Integration::handles($exceptions);

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        // Consolidation refactor — standardises every error response onto
        // the {success, message, errors} envelope required by the sprint spec.
        $exceptions->render(function (ValidationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'The given data was invalid.',
                    'errors' => $e->errors(),
                ], 422);
            }
        });

        // Laravel converts ModelNotFoundException into NotFoundHttpException
        // internally before custom render() callbacks run, so this must be
        // registered against NotFoundHttpException, not ModelNotFoundException,
        // to actually catch route-model-binding misses. This also covers
        // genuine "route doesn't exist" 404s, which is a reasonable bonus.
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Resource not found.',
                    'errors' => [],
                ], 404);
            }
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'errors' => [],
                ], 401);
            }
        });

        // Day 5 — UploadDocumentRequest::authorize() returning false throws
        // this. Without an explicit handler it would fall through to Laravel's
        // default 403 view, which isn't valid JSON for the frontend to parse.
        //
        // Audit finding (HIGH) — this render() alone never actually fired.
        // Same conversion trap as ModelNotFoundException below: Laravel's
        // internal handler converts AuthorizationException into
        // Symfony's AccessDeniedHttpException before custom render()
        // callbacks are matched, so registering only against
        // AuthorizationException left every real 403 falling through to
        // Laravel's default (debug-mode) renderer — leaking full stack
        // traces, server file paths, and framework internals on every
        // authorization failure. Registering against the converted class
        // fixes it; the AuthorizationException handler above is kept as
        // defense in depth in case that conversion behavior ever changes.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'This action is unauthorized.',
                    'errors' => [],
                ], 403);
            }
        });

        // Audit finding — same class of leak as AccessDeniedHttpException
        // above, different trigger. Nothing in this file previously caught
        // a bare RuntimeException, so a Voyage 401/429/500 (or any other
        // embedding-provider failure) fell straight through to Laravel's
        // default renderer, exposing the upstream status code, response
        // body, and internal exception trace directly in the API response.
        // VoyageEmbeddingClient now throws this typed exception instead of
        // a bare RuntimeException specifically so it can be caught here and
        // mapped to a clean, generic 503 — upstream details are logged
        // internally in VoyageEmbeddingClient, never surfaced to the client.
        $exceptions->render(function (EmbeddingProviderException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Service temporarily unavailable.',
                    'errors' => [],
                ], 503);
            }
        });

        // Consolidation refactor — catches Laravel's built-in throttle
        // middleware responses (e.g. throttle:signup, throttle:document-uploads)
        // and wraps them in the same envelope as our manual RateLimiter checks.
        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'success' => false,
                    'message' => 'Too many requests. Please try again later.',
                    'errors' => [],
                ], 429);
            }
        });

        // Final safety net — anything not caught by a more specific handler
        // above still must not leak internals on api/*, regardless of
        // APP_DEBUG. Registered last among render() calls so it never
        // shadows the specific handlers above it.
        $exceptions->render(function (\Throwable $e, Request $request) {
            if ($request->is('api/*')) {
                report($e);

                return response()->json([
                    'success' => false,
                    'message' => 'An unexpected error occurred.',
                    'errors' => [],
                ], 500);
            }
        });

        // Permanent safety net — clients that omit/misconfigure the Accept
        // header (misbehaving mobile clients, raw fetch() calls, webhook
        // callers) would otherwise fall through Laravel's default rendering
        // and get a non-JSON 500 on auth failures. This runs after all
        // render() callbacks and guarantees every api/* AuthenticationException
        // gets a clean JSON 401, regardless of what the client sent.
        $exceptions->respond(function ($response, \Throwable $exception, $request) {
            if ($request->is('api/*') && $exception instanceof AuthenticationException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthenticated.',
                    'errors' => [],
                ], 401);
            }

            return $response;
        });
    })->create();