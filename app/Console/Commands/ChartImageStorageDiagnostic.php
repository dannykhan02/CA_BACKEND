<?php

namespace App\Console\Commands;

use Aws\Exception\AwsException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Explicit, synthetic cross-service R2 probe. Never scans or reads document objects. */
class ChartImageStorageDiagnostic extends Command
{
    protected $signature = 'chart-images:storage-diagnostic
        {phase : write, verify, or cleanup}
        {--run= : UUID from the worker write phase}
        {--sha256= : SHA-256 printed by the worker write phase}
        {--expected-service= : Railway service name expected for this process}
        {--expected-environment= : Railway environment name expected for this process}
        {--public-base-url=* : Every configured public bucket domain to probe without credentials}
        {--public-access-reviewed : Operator checked that no other R2 public domains are enabled}';

    protected $description = 'Explicit worker-write/web-verify/web-cleanup test of the private documents disk.';

    private const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNwKo/7DwAELQIX8FO9MwAAAABJRU5ErkJggg==';

    public function handle(): int
    {
        $phase = (string) $this->argument('phase');
        if (! in_array($phase, ['write', 'verify', 'cleanup'], true)) {
            return $this->failure('Unknown phase. Use write, verify, or cleanup.');
        }
        $service = getenv('RAILWAY_SERVICE_NAME') ?: '';
        $environment = getenv('RAILWAY_ENVIRONMENT_NAME') ?: '';
        if ($service === '' || $environment === ''
            || $service !== $this->option('expected-service')
            || $environment !== $this->option('expected-environment')) {
            return $this->failure('Railway service/environment identity is absent or differs from the expected target.');
        }
        if (($phase === 'write' && $service !== 'ca-horizon-worker')
            || ($phase !== 'write' && $service !== 'CA_BACKEND')) {
            return $this->failure('Use the Horizon worker for write and the backend web service for verify/cleanup.');
        }
        $config = config('filesystems.disks.documents');
        if (($config['driver'] ?? null) !== 's3' || ($config['visibility'] ?? null) !== 'private'
            || ($config['use_path_style_endpoint'] ?? null) !== true
            || ! is_string($config['bucket'] ?? null) || $config['bucket'] === ''
            || ! is_string($config['endpoint'] ?? null) || ! str_starts_with($config['endpoint'], 'https://')
            || ! is_string($config['key'] ?? null) || $config['key'] === ''
            || ! is_string($config['secret'] ?? null) || $config['secret'] === '') {
            return $this->failure('The documents disk is not a complete private R2/S3 configuration.');
        }
        $fingerprint = substr(hash('sha256', json_encode([
            $config['driver'], $config['endpoint'], $config['bucket'],
            $config['use_path_style_endpoint'], $config['visibility'],
        ])), 0, 16);
        $this->line("service={$service} environment={$environment} storage_config_fingerprint={$fingerprint}");

        $run = $this->option('run');
        if ($phase === 'write' && $run === null) {
            $run = (string) Str::uuid();
        }
        if (! is_string($run) || ! Str::isUuid($run) || strtolower($run[14]) !== '4') {
            return $this->failure('A version-4 UUID run identifier is required.');
        }
        $path = "chart-storage-diagnostics/{$run}/probe.png";
        $expected = $this->option('sha256');
        if ($phase !== 'write' && (! is_string($expected) || ! preg_match('/^[a-f0-9]{64}$/', $expected))) {
            return $this->failure('A valid SHA-256 from the worker write phase is required.');
        }

        try {
            $disk = Storage::disk('documents');
            if ($phase === 'write') {
                if ($disk->exists($path)) return $this->failure('Synthetic run already exists; choose another UUID.');
                $bytes = base64_decode(self::PNG_BASE64, true);
                if (! is_string($bytes) || ! $disk->put($path, $bytes, 'private')) {
                    return $this->failure('Worker write failed.');
                }
                $read = $disk->get($path);
                if (! is_string($read) || ! hash_equals(hash('sha256', $bytes), hash('sha256', $read))) {
                    return $this->failure('Worker read-back hash differed after write.');
                }
                $this->info("worker_write=ok run={$run} sha256=".hash('sha256', $bytes));
                $this->line('Next: run verify on the web service, then cleanup even if verification fails.');

                return self::SUCCESS;
            }

            if (! $disk->exists($path)) return $this->failure('Synthetic object is missing from this service.');
            $bytes = $disk->get($path);
            if (! is_string($bytes) || ! hash_equals($expected, hash('sha256', $bytes))) {
                return $this->failure('Synthetic image SHA-256 mismatch; cleanup refused.');
            }
            if ($phase === 'verify') {
                $this->line('web_read_and_hash=ok');
                if (! $this->checkPublicAccess($config, $path)) return self::FAILURE;
                $this->info('cross_service_read=ok unsigned_access=denied');
                $this->line('Next: run cleanup on the web service with the same run and SHA-256.');

                return self::SUCCESS;
            }

            if (! $disk->delete($path) || $disk->exists($path)) {
                return $this->failure('Synthetic object deletion was not confirmed.');
            }
            $this->info('synthetic_object_deleted=ok');

            return self::SUCCESS;
        } catch (\Throwable $error) {
            return $this->failure("{$phase} failed (".$this->classify($error).'). No credentials or object contents were printed.');
        }
    }

    private function checkPublicAccess(array $config, string $path): bool
    {
        $bucket = rawurlencode($config['bucket']);
        $key = implode('/', array_map('rawurlencode', explode('/', $path)));
        $urls = [rtrim($config['endpoint'], '/')."/{$bucket}/{$key}"];
        foreach ($this->option('public-base-url') as $base) {
            if (! is_string($base) || ! str_starts_with($base, 'https://')
                || ! filter_var($base, FILTER_VALIDATE_URL)) {
                $this->failure('A public-base-url must be a valid HTTPS URL.');

                return false;
            }
            $urls[] = rtrim($base, '/').'/'.$key;
        }
        foreach ($urls as $index => $url) {
            try {
                $status = Http::timeout(10)->withoutRedirecting()->get($url)->status();
            } catch (\Throwable $error) {
                $this->failure('Unsigned access check failed ('.$this->classify($error).').');

                return false;
            }
            if (! in_array($status, [401, 403, 404], true)) {
                $this->failure('Unsigned access was not denied for endpoint '.($index + 1)." (HTTP {$status}).");

                return false;
            }
        }
        if (! $this->option('public-access-reviewed')) {
            $this->failure('Unsigned endpoints denied access, but Cloudflare public-domain settings have not been reviewed.');

            return false;
        }

        return true;
    }

    private function failure(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    private function classify(\Throwable $error): string
    {
        for ($current = $error; $current !== null; $current = $current->getPrevious()) {
            if ($current instanceof AwsException) {
                $code = $current->getAwsErrorCode();
                return match ($code) {
                    'AccessDenied' => 'R2 permission denied',
                    'InvalidAccessKeyId', 'SignatureDoesNotMatch' => 'R2 credentials or signature rejected',
                    'NoSuchBucket' => 'R2 bucket not found',
                    'PermanentRedirect', 'AuthorizationHeaderMalformed' => 'R2 endpoint or region mismatch',
                    'RequestTimeout', 'SlowDown', 'ServiceUnavailable' => 'R2 transient service failure',
                    default => 'R2 request failed, HTTP '.($current->getStatusCode() ?: 'unknown'),
                };
            }
            if ($current instanceof ConnectException) return 'network or DNS connection failure';
        }

        return $error::class;
    }
}
