<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Audit F-Low-3: several real leaks/gaps (OTP/reset-token exposure in API
 * responses, malware scanning skipped entirely) are each gated by a single
 * config boolean. This command asserts they're at their safe production
 * values and exits non-zero if not, so it can be wired into a deploy
 * pipeline as a hard gate rather than trusted to .env review alone.
 *
 * Suggested usage in CI/CD, before allowing a production deploy to proceed:
 *   php artisan config:check-production-safety
 */
class CheckProductionSafetyConfig extends Command
{
    protected $signature = 'config:check-production-safety';
    protected $description = 'Fails if any debug/dev-only config flag is unsafe for production.';

    public function handle(): int
    {
        if (! app()->environment('production')) {
            $this->info('Not running in the production environment — skipping (this check only applies there).');
            return self::SUCCESS;
        }

        $failures = [];

        if (config('auth.developer.expose_verification_code') === true) {
            $failures[] = 'auth.developer.expose_verification_code is TRUE in production — email/OTP codes are being returned in API responses.';
        }

        if (config('auth.developer.expose_password_reset_token') === true) {
            $failures[] = 'auth.developer.expose_password_reset_token is TRUE in production — password reset tokens are being returned in API responses.';
        }

        if (config('document_processing.clamav_enabled') !== true) {
            $failures[] = 'document_processing.clamav_enabled is not TRUE in production — malware scanning is being skipped for all uploads.';
        }

        if (config('app.debug') === true) {
            $failures[] = 'app.debug is TRUE in production — stack traces and internals may be exposed on errors.';
        }

        if (empty($failures)) {
            $this->info('All production safety checks passed.');
            return self::SUCCESS;
        }

        $this->error('Production safety check FAILED:');
        foreach ($failures as $failure) {
            $this->error("  - {$failure}");
        }

        return self::FAILURE;
    }
}
