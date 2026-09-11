<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Audit AUTH-1: previously, an account that only ever completed signup
 * (and never entered the emailed OTP) still received a fully-working
 * Sanctum token, usable on every 'auth:sanctum' route except signin()
 * itself. This middleware closes that gap for every route it's applied to.
 *
 * Deliberately NOT applied to /auth/me, /auth/signout, or
 * /auth/change-email/* — an unverified user still needs those to inspect
 * their own account, sign out, or correct a mistyped email pre-verification.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->user() || ! $request->user()->email_verified_at) {
            return response()->json([
                'message' => 'Please verify your email address before continuing.',
            ], 403);
        }

        return $next($request);
    }
}
