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
use App\Http\Requests\Auth\ChangeEmailRequest;
use App\Http\Requests\Auth\ConfirmEmailChangeRequest;
use App\Models\User;
use App\Models\VerificationCode;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PasswordResetNotification;
use App\Notifications\VerificationCodeNotification;
use App\Notifications\WelcomeNotification;
use App\Notifications\EmailChangeVerificationNotification;
use App\Notifications\EmailChangedNotification;
use App\Services\GoogleTokenVerifier;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    private const MAX_ATTEMPTS = 5;
    private const LOCKOUT_SECONDS = 60;
    private const DEFAULT_TOKEN_HOURS = 2;
    private const REMEMBER_ME_DAYS = 30;

    // Secondary, IP-only signin limiter — the existing per-(email,IP) limiter
    // never trips for an attacker rotating IPs against one email, or hammering
    // many emails from one IP. This is a coarser backstop on top of it.
    private const IP_MAX_ATTEMPTS = 30;
    private const IP_LOCKOUT_SECONDS = 300;

    private const RESEND_MAX_ATTEMPTS = 3;
    private const RESEND_LOCKOUT_SECONDS = 600;
    private const FORGOT_PASSWORD_MAX_ATTEMPTS = 3;
    private const FORGOT_PASSWORD_LOCKOUT_SECONDS = 600;

    private const VERIFY_MAX_ATTEMPTS = 5;
    private const VERIFY_LOCKOUT_SECONDS = 300;

    public function signup(SignupRequest $request): JsonResponse
    {
        $validated = $request->validated();

        // User creation and the personal workspace it needs are one atomic
        // unit — a user must never exist without a workspace to belong to
        // (would leave current_workspace_id null and no workspace_members
        // row, breaking every workspace-scoped query for that account).
        $user = DB::transaction(function () use ($validated) {
            $user = User::create([
                'full_name' => $validated['full_name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
                'role' => 'Viewer',
            ]);

            app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

            return $user;
        });

        $user->refresh();

        $verification = VerificationCode::generateFor($user);
        $user->notify(new VerificationCodeNotification($verification->plainCode));

        if (! app()->environment('production')) {
            Log::info("Verification code for {$user->email}: {$verification->plainCode}");
        }

        $devCode = config('auth.developer.expose_verification_code') ? $verification->plainCode : null;
        $token = $user->createToken('auth-token', ['*'], now()->addHours(self::DEFAULT_TOKEN_HOURS))->plainTextToken;

        $data = [
            'user' => $this->userPayload($user),
            'token' => $token,
        ];

        if ($devCode !== null) {
            $data['verification_code'] = $devCode;
        }

        return $this->success('Account created successfully.', $data, 201);
    }

    public function signin(SigninRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $throttleKey = $this->loginThrottleKey($validated['email'], $request->ip());
        $ipThrottleKey = 'login-ip:' . $request->ip();

        if (RateLimiter::tooManyAttempts($throttleKey, self::MAX_ATTEMPTS)
            || RateLimiter::tooManyAttempts($ipThrottleKey, self::IP_MAX_ATTEMPTS)
        ) {
            $seconds = max(
                RateLimiter::availableIn($throttleKey),
                RateLimiter::availableIn($ipThrottleKey)
            );
            return $this->error("Too many failed login attempts. Try again in {$seconds} seconds.", ['retry_after' => $seconds], 429);
        }

        $user = User::where('email', $validated['email'])->first();

        // Collapsed enumeration-safe failure path (audit F-High-1): bad
        // credentials, a deactivated account, and an unverified account all
        // return the exact same status code and message now. Internally we
        // still branch so the *hit* behavior and logging can differ, but the
        // wire response is identical in all three cases.
        $genericAuthFailure = fn () => $this->error('These credentials do not match our records.', [], 401);

        if (! $user || ! Hash::check($validated['password'], $user->password)) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);
            RateLimiter::hit($ipThrottleKey, self::IP_LOCKOUT_SECONDS);
            return $genericAuthFailure();
        }

        if (! $user->active) {
            RateLimiter::hit($throttleKey, self::LOCKOUT_SECONDS);
            RateLimiter::hit($ipThrottleKey, self::IP_LOCKOUT_SECONDS);
            Log::info('Signin attempt on deactivated account.', ['user_id' => $user->id]);
            return $genericAuthFailure();
        }

        if (! $user->email_verified_at) {
            RateLimiter::hit($ipThrottleKey, self::IP_LOCKOUT_SECONDS);
            Log::info('Signin attempt on unverified account.', ['user_id' => $user->id]);
            return $genericAuthFailure();
        }

        RateLimiter::clear($throttleKey);
        RateLimiter::clear($ipThrottleKey);

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

    public function google(GoogleSigninRequest $request, GoogleTokenVerifier $verifier): JsonResponse
    {
        $idToken = $request->validated('id_token');

        // Local signature verification against Google's published JWKs
        // instead of the legacy /tokeninfo debugging endpoint (audit
        // F-High-4). Throws on any signature/claim failure.
        try {
            $claims = $verifier->verify($idToken, config('services.google.client_id'));
        } catch (\Throwable $e) {
            Log::warning('Google sign-in token verification failed.', ['error' => $e->getMessage()]);
            return $this->error('Invalid or expired Google token.', [], 401);
        }

        if (($claims['email_verified'] ?? false) !== true) {
            return $this->error('Google account email is not verified.', [], 401);
        }

        $email = strtolower($claims['email'] ?? '');
        $name = $claims['name'] ?? $email;

        if (! $email) {
            return $this->error('Google token did not include an email address.', [], 422);
        }

        $user = User::where('email', $email)->first();
        $isNewUser = ! $user;

        // Google's own `email_verified` claim (checked above) is treated as
        // equivalent to completing our own OTP flow — so unlike signup(),
        // there is no VerificationCode generated and no verification email
        // sent here. Do not add one; it would ask the user to re-verify an
        // address Google has already verified.
        if ($isNewUser) {
            // Same atomicity requirement as signup(): a user must never be
            // created without a personal workspace.
            $user = DB::transaction(function () use ($name, $email) {
                $user = User::create([
                    'full_name' => $name,
                    'email' => $email,
                    'password' => Str::random(32),
                    'role' => 'Viewer',
                ]);

                app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

                return $user;
            });

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

    public function requestEmailChange(ChangeEmailRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! Hash::check($validated['current_password'], $user->password)) {
            return $this->error('Current password is incorrect.', [], 401);
        }

        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $user->forceFill([
            'pending_email' => mb_strtolower(trim($validated['new_email'])),
            // Hashed at rest (audit hardening item): previously stored in
            // plaintext, identical to the reset-token pattern Laravel's own
            // Password broker already uses for password-reset tokens.
            'pending_email_code' => Hash::make($code),
            'pending_email_expires_at' => now()->addMinutes(15),
        ])->save();

        // Must route explicitly to the NEW address — $user->notify() would
        // default to routeNotificationForMail(), which resolves to $user->email
        // (the OLD address), since the change isn't confirmed yet. Confirmed
        // via a real Resend rejection: the code was silently being sent to the
        // current email instead of pending_email.
        Notification::route('mail', $user->pending_email)
            ->notify(new EmailChangeVerificationNotification($code));

        if (! app()->environment('production')) {
            Log::info("Email change code requested for user {$user->id}");
        }

        $devCode = config('auth.developer.expose_verification_code') ? $code : null;

        return $this->success('Verification code sent to your new email address.', [
            'verification_code' => $devCode,
        ]);
    }

    public function confirmEmailChange(ConfirmEmailChangeRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $user = $request->user();

        if (! $user->pending_email || ! $user->pending_email_code) {
            return $this->error('No pending email change request found.', [], 404);
        }

        if ($user->pending_email_expires_at->isPast()) {
            return $this->error('This code has expired. Please request the change again.', [], 410);
        }

        if (! Hash::check($validated['code'], $user->pending_email_code)) {
            return $this->error('Invalid verification code.', [], 422);
        }

        $oldEmail = $user->email;
        $newEmail = $user->pending_email;

        // Wrapped in a transaction with a catch on the unique-constraint race
        // (audit F-Medium-2): two users requesting the same new_email
        // concurrently can both pass ChangeEmailRequest's uniqueness check
        // (which only checks the CONFIRMED users.email column) before either
        // confirms. Whoever confirms first wins; the second now gets a clean
        // 409 instead of an unhandled QueryException/500.
        try {
            DB::transaction(function () use ($user, $newEmail) {
                $user->forceFill([
                    'email' => $newEmail,
                    'email_verified_at' => now(),
                    'pending_email' => null,
                    'pending_email_code' => null,
                    'pending_email_expires_at' => null,
                ])->save();
            });
        } catch (\Illuminate\Database\QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                return $this->error('That email address was just taken by another account. Please choose a different one.', [], 409);
            }
            throw $e;
        }

        // Notify the OLD address, not the new one — this is the security
        // alert ("did you mean to do this?"), so it must reach whoever
        // controlled the account before the change, not the new inbox.
        // Wrapped: the email change above has already committed to the DB,
        // so a delivery failure here (bad DNS, provider outage, a fixture
        // test address with no real mailbox, etc.) must not turn a
        // successful change into a 500 response to the client.
        try {
            Notification::route('mail', $oldEmail)
                ->notify(new EmailChangedNotification($newEmail));
        } catch (\Throwable $e) {
            Log::error('Failed to send email-changed security alert.', [
                'old_email' => $oldEmail,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->success('Email address updated successfully.', [
            'user' => $this->userPayload($user),
        ]);
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
            RateLimiter::hit($attemptKey, self::VERIFY_LOCKOUT_SECONDS);
            return $this->error('Invalid verification request.', [], 404);
        }

        if ($user->email_verified_at) {
            return $this->error('This account is already verified.', [], 409);
        }

        // Codes are hashed at rest now, so we can no longer look the row up
        // by ->where('code', ...). Instead pull the user's latest code and
        // verify the hash — generateFor() guarantees at most one live row.
        $verification = VerificationCode::where('user_id', $user->id)->latest()->first();

        if (! $verification || ! Hash::check($validated['code'], $verification->code)) {
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
            $user->notify(new VerificationCodeNotification($verification->plainCode));

            if (! app()->environment('production')) {
                Log::info("Verification code for {$user->email}: {$verification->plainCode}");
            }
            $devCode = config('auth.developer.expose_verification_code') ? $verification->plainCode : null;
        }

        $data = [];
        if ($devCode !== null) {
            $data['verification_code'] = $devCode;
        }

        return $this->success(
            'If an account with that email exists and is unverified, a new code has been sent.',
            $data
        );
    }

    public function forgotPassword(ForgotPasswordRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $throttleKey = 'forgot-password:' . Str::lower($validated['email']);

        if (RateLimiter::tooManyAttempts($throttleKey, self::FORGOT_PASSWORD_MAX_ATTEMPTS)) {
            $seconds = RateLimiter::availableIn($throttleKey);
            return $this->error("Too many requests. Try again in {$seconds} seconds.", ['retry_after' => $seconds], 429);
        }

        RateLimiter::hit($throttleKey, self::FORGOT_PASSWORD_LOCKOUT_SECONDS);

        $user = User::where('email', $validated['email'])->first();
        $devToken = null;

        if ($user) {
            $token = Password::broker()->createToken($user);
            $user->notify(new PasswordResetNotification($token, $user->email));

            if (! app()->environment('production')) {
                Log::info("Password reset requested for user {$user->id}");
            }

            $devToken = config('auth.developer.expose_password_reset_token') ? $token : null;
        }

        $data = [];
        if ($devToken !== null) {
            $data['reset_token'] = $devToken;
        }

        return $this->success(
            'If an account with that email exists, a password reset link has been sent.',
            $data
        );
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
            'workspace' => $user->currentWorkspace ? [
                'id' => $user->currentWorkspace->id,
                'type' => $user->currentWorkspace->type->value,
            ] : null,
        ];
    }
}




