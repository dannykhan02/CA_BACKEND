<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Additional hardening: baseline security headers weren't present anywhere
 * in the reviewed files. These are cheap, broadly-recommended defaults for
 * a JSON API — register globally in bootstrap/app.php (see implementation
 * guide) so every response gets them, not just specific routes.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Permissions-Policy', 'geolocation=(), camera=(), microphone=()');

        // Only meaningful over HTTPS; harmless to set unconditionally, but
        // confirm the app is always served over TLS in production before
        // relying on this — it will not upgrade an HTTP connection itself.
        if ($request->secure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }
}
