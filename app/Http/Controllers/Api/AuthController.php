<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\GoogleSigninRequest;
use App\Http\Requests\Auth\ResendVerificationRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Http\Requests\Auth\SigninRequest;
use App\Http\Requests\Auth\SignupRequest;
use App\Http\Requests\Auth\VerifyEmailRequest;
use App\Models\User;
use App\Models\VerificationCode;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetNotification;
use App\Notifications\VerificationCodeNotification;
use App\Notifications\WelcomeNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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

    private const RESEND_MAX_ATTEMPTS = 3;
    private const RESEND_LOCKOUT_SECONDS = 600;
    private const FORGOT_PASSWORD_MAX_ATTEMPTS = 3;
    private const FORGOT_PASSWORD_LOCKOUT_SECONDS = 600;

    private const VERIFY_MAX_ATTEMPTS = 5;
    private const VERIFY_LOCKOUT_SECONDS = 300;

    public function signup(SignupRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $user = User::create([
            'full_name' => $validated['full_name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => 'Viewer',
        ]);

        $user->refresh();

        $verification = VerificationCode::generateFor($user);
        $user->notify(new VerificationCodeNotification($verification->code));

        if (! app()->environment('production')) {
            Log::info("Verification code for {$user->email}: {$verification->code}");
        }

        $devCode = config('auth.developer.expose_verification_code') ? $verification->code : null;
        $token = $user->createToken('auth-token', ['*'], now()->addHours(self::DEFAULT_TOKEN_HOURS))->plainTextToken;

        $response = [
            'message' => 'Account created successfully.',
            'data' => [
                'user' => $this->userPayload($user),
                'token' => $token,
            ],
        ];

        if ($devCode !== null) {
            $response['data']['verification_code'] = $devCode;
        }

        return response()->json($response, 201);
    }

    public function signin(SigninRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $throttleKey = $this->loginThrottleKey($validated['email'], $request->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return $this->error("Too many failed login attempts. Try again in {$seconds} seconds.", ['retry_after' => $seconds], 429);
        }

        $user = User::where('email', $validated['email'])->first();

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);
            return $this->error('These credentials do not match our records.', [], 401);
        }

        if (! $user->active) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);
            return $this->error('This account has been deactivated. Contact an administrator.', ['error' => 'account_deactivated'], 403);
        }

        if (! $user->email_verified_at) {
            return $this->error('Please verify your email before signing in.', ['error' => 'email_not_verified'], 403);
        }

        RateLimiter::clear($throttleKey);

        $rememberMe = $validated['remember_me'] ?? false;
        $expiresAt = $rememberMe
            ? now()->addDays(self::REMEMBER_ME_DAYS)
            : now()->addHours(self::DEFAULT_TOKEN_HOURS);

        $token = $user->createToken('auth-token', ['*'], $expiresAt)->plainTextToken;
        $user->forceFill(['last_active_at' => now()])->save();

        return $this->success('Signed in successfully.', [
            'user' => $this->userPayload($user),
            'token' => $token,
            'expires_at' => $expiresAt->toIso8601String(),
        ]);
    }

    public function google(GoogleSigninRequest $request): JsonResponse
    {
        $idToken = $request->validated('id_token');

        $response = Http::get('https://oauth2.googleapis.com/tokeninfo', [
            'id_token' => $idToken,
        ]);

        if ($response->failed()) {
            return $this->error('Invalid or expired Google token.', [], 401);
        }

        $claims = $response->json();

        $expectedClientId = config('services.google.client_id');
        if (! $expectedClientId || ($claims['aud'] ?? null) !== $expectedClientId) {
            Log::warning('Google sign-in token audience mismatch.');
            return $this->error('Invalid Google token.', [], 401);
        }

        if (($claims['email_verified'] ?? 'false') !== 'true') {
            return $this->error('Google account email is not verified.', [], 401);
        }

        $email = strtolower($claims['email'] ?? '');
        $name = $claims['name'] ?? $email;

        if (! $email) {
            return $this->error('Google token did not include an email address.', [], 422);
        }

        $user = User::where('email', $email)->first();
        $isNewUser = ! $user;

        if ($isNewUser) {
            $user = User::create([
                'full_name' => $name,
                'email' => $email,
                'password' => Str::random(32),
                'role' => 'Viewer',
            ]);
            $user->refresh(); // pull DB-assigned defaults (role, active) back into the model
            $user->forceFill(['email_verified_at' => now()])->save();
        } elseif (! $user->email_verified_at) {
            $user->forceFill(['email_verified_at' => now()])->save();
        }

        if (! $user->active) {
            return $this->error('This account has been deactivated. Contact an administrator.', ['error' => 'account_deactivated'], 403);
        }

        $token = $user->createToken('auth-token', ['*'], now()->addHours(self::DEFAULT_TOKEN_HOURS))->plainTextToken;
        $user->forceFill(['last_active_at' => now()])->save();

        if ($isNewUser) {
            $user->notify(new WelcomeNotification());
        }

        return $this->success('Signed in successfully.', [
            'user' => $this->userPayload($user),
            'token' => $token,
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        return $this->success('Current user retrieved.', [
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function signout(): JsonResponse
    {
        /** @var User $user */
        $user = Auth::user();
        $user->currentAccessToken()->delete();

        return $this->success('Signed out successfully.');
    }

    public function verifyEmail(VerifyEmailRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $attemptKey = 'verify-attempts:' . Str::lower($validated['email']);

        if (RateLimiter::tooManyAttempts($attemptKey, self::VERIFY_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($attemptKey);
            return $this->error("Too many incorrect attempts. Try again in {$seconds} seconds.", ['retry_after' => $seconds], 429);
        }

        $user = User::where('email', $validated['email'])->first();

        if (! $user) {
            return $this->error('Invalid verification request.', [], 404);
        }

        if ($user->email_verified_at) {
            return $this->error('This account is already verified.', [], 409);
        }

        $verification = VerificationCode::where('user_id', $user->id)
            ->where('code', $validated['code'])
            ->latest()
            ->first();

        if (! $verification) {
            RateLimiter::hit($attemptKey, self::VERIFY_LOCKOUT_SECONDS);
            return $this->error('Invalid verification code.', [], 422);
        }

        if ($verification->isExpired()) {
            return $this->error('This verification code has expired. Request a new one.', [], 410);
        }

        RateLimiter::clear($attemptKey);
        $user->forceFill(['email_verified_at' => now()])->save();
        $verification->delete();
        $user->notify(new WelcomeNotification());

        return $this->success('Email verified successfully.');
    }

    public function resendVerification(ResendVerificationRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $throttleKey = 'resend-verification:' . Str::lower($validated['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, self::RESEND_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return $this->error("Too many requests. Try again in {$seconds} seconds.", ['retry_after' => $seconds], 429);
        }

        $user = User::where('email', $validated['email'])->first();
        $devCode = null;

        if ($user && ! $user->email_verified_at) {
            RateLimiter::hit($throttleKey, self::RESEND_LOCKOUT_SECONDS);

            $verification = VerificationCode::generateFor($user);
            $user->notify(new VerificationCodeNotification($verification->code));

            if (! app()->environment('production')) {
                Log::info("Verification code for {$user->email}: {$verification->code}");
            }
            $devCode = config('auth.developer.expose_verification_code') ? $verification->code : null;
        }

        $response = [
            'message' => 'If an account with that email exists and is unverified, a new code has been sent.',
        ];

        if ($devCode !== null) {
            $response['verification_code'] = $devCode;
        }

        return response()->json($response, 200);
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $throttleKey = 'forgot-password:' . Str::lower($validated['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, self::FORGOT_PASSWORD_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return $this->error("Too many requests. Try again in {$seconds} seconds.", ['retry_after' => $seconds], 429);
        }

        $user = User::where('email', $validated['email'])->first();
        $devToken = null;

        if ($user) {
            RateLimiter::hit($throttleKey, self::FORGOT_PASSWORD_LOCKOUT_SECONDS);

            $token = Password::broker()->createToken($user);
            $user->notify(new PasswordResetNotification($token, $user->email));

            if (! app()->environment('production')) {
                Log::info("Password reset token for {$user->email}: {$token}");
            }
            $devToken = config('auth.developer.expose_password_reset_token') ? $token : null;
        }

        $response = [
            'message' => 'If an account with that email exists, a password reset link has been sent.',
        ];

        if ($devToken !== null) {
            $response['reset_token'] = $devToken;
        }

        return response()->json($response, 200);
    }

    public function resetPassword(ResetPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $status = Password::reset(
            $validated,
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
                $user->tokens()->delete();
                $user->notify(new PasswordChangedNotification());
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return $this->error(__($status), [], 422);
        }

        return $this->success('Password reset successfully.');
    }

    private function loginThrottleKey(string $email, string $ip): string
    {
        return 'login:' . Str::lower($email) . '|' . $ip;
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