<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SigninRequest;
use App\Http\Requests\Auth\SignupRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Models\User;
use App\Models\VerificationCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 60;
    private const DEFAULT_TOKEN_HOURS = 2;
    private const REMEMBER_ME_DAYS = 30;

    public function signup(SignupRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // Interim default: new signups receive 'Viewer' role pending explicit client
        // confirmation (per roadmap Section 8). This is now set explicitly rather than
        // relying on the migration default, so it cannot silently change if the DB
        // schema is altered in the future.
        $user = User::create([
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => 'Viewer',
        ]);

        $user->refresh(); // pull DB-assigned defaults (role, active) back into the model

        $verification = VerificationCode::generateFor($user);
        Log::info("Verification code for {$user->email}: {$verification->code}");
        // Surfaced directly to the client for now — no mail transport / ca.go.ke DNS in place yet.
        // Gate this off (or remove) once real delivery is configured.
        $devCode = app()->environment(['local', 'staging']) ? $verification->code : null;

        $token = $user->createToken('auth-token', ['*'], now()->addHours(self::DEFAULT_TOKEN_HOURS))->plainTextToken;

        return response()->json([
            'message' => 'Account created successfully.',
            'data' => [
                'user' => $this->userPayload($user),
                'token' => $token,
                'verification_code' => $devCode,
            ],
        ], 201);
    }

    public function signin(SigninRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $throttleKey = $this->throttleKey($validated['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);

            return response()->json([
                'message' => "Too many failed login attempts. Try again in {$seconds} seconds.",
                'retry_after' => $seconds,
            ], 429);
        }

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

            return response()->json([
                'message' => 'These credentials do not match our records.',
            ], 401);
        }

        if (! $user->active) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);

            return response()->json([
                'message' => 'This account has been deactivated. Contact an administrator.',
                'error' => 'account_deactivated',
            ], 403);
        }

        RateLimiter::clear($throttleKey);

        $rememberMe = $validated['remember_me'] ?? false;
        $expiresAt = $rememberMe
            ? now()->addDays(self::REMEMBER_ME_DAYS)
            : now()->addHours(self::DEFAULT_TOKEN_HOURS);

        $token = $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;

        $user->forceFill(['last_active_at' => now()])->save();

        return response()->json([
            'message' => 'Signed in successfully.',
            'data' => [
                'user' => $this->userPayload($user),
                'token' => $token,
                'expires_at' => $expiresAt->toIso8601String(),
            ],
        ]);
    }

    public function signout(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $user->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Signed out successfully.',
        ]);
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return response()->json(['message' => 'Invalid verification request.'], 404);
        }

        if ($user->email_verified_at) {
            return response()->json(['message' => 'This account is already verified.'], 409);
        }

        $verification = VerificationCode::where('user_id', $user->id)
            ->where('code', $validated['code'])
            ->latest()
            ->first();

        if (! $verification) {
            return response()->json(['message' => 'Invalid verification code.'], 422);
        }

        if ($verification->isExpired()) {
            return response()->json(['message' => 'This verification code has expired. Request a new one.'], 410);
        }

        $user->forceFill(['email_verified_at' => now()])->save();
        $verification->delete();

        return response()->json(['message' => 'Email verified successfully.']);
    }

    public function resendVerification(ResendVerificationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = User::where('email', $validated['email'])->first();

        $devCode = null;
        if ($user && ! $user->email_verified_at) {
            $verification = VerificationCode::generateFor($user);
            Log::info("Verification code for {$user->email}: {$verification->code}");
            $devCode = app()->environment(['local', 'staging']) ? $verification->code : null;
        }

        return response()->json([
            'message' => 'If an account with that email exists and is unverified, a new code has been sent.',
            'verification_code' => $devCode,
        ]);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = User::where('email', $validated['email'])->first();

        $devToken = null;
        if ($user) {
            $token = Password::broker()->createToken($user);
            Log::info("Password reset token for {$user->email}: {$token}");
            $devToken = app()->environment(['local', 'staging']) ? $token : null;
        }

        return response()->json([
            'message' => 'If an account with that email exists, a password reset link has been sent.',
            'reset_token' => $devToken,
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete(); // invalidate existing sessions on password change
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => __($status)], 422);
        }

        return response()->json(['message' => 'Password reset successfully.']);
    }

    private function throttleKey(string $email): string
    {
        return 'login:' . Str::lower($email);
    }

    private function userPayload(User $user): array
    {
        return [
            'id' => $user->id,
            'email' => $user->email,
            'full_name' => $user->full_name,
            'role' => $user->role,
            'email_verified_at' => $user->email_verified_at,
            'active' => $user->active,
            'created_at' => $user->created_at?->toIso8601String(),
        ];
    }
}