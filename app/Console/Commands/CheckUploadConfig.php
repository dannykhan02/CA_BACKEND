<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CheckUploadConfig extends Command
{
    protected $signature = 'system:check-upload-config';
    protected $description = "Fail loudly if php.ini upload limits are smaller than the app's configured max upload size";

    public function handle(): int
    {
        $appMaxKb = (int) config('document_processing.max_upload_size_kb');
        $appMaxBytes = $appMaxKb * 1024;
        $phpUploadMaxBytes = $this->toBytes(ini_get('upload_max_filesize'));
        $phpPostMaxBytes = $this->toBytes(ini_get('post_max_size'));

        $this->line("App configured max upload:   {$appMaxKb} KB (" . round($appMaxBytes / 1048576, 1) . " MB)");
        $this->line('php.ini upload_max_filesize: ' . ini_get('upload_max_filesize'));
        $this->line('php.ini post_max_size:       ' . ini_get('post_max_size'));

        $ok = true;

        if ($phpUploadMaxBytes < $appMaxBytes) {
            $this->error("upload_max_filesize is smaller than DOC_MAX_UPLOAD_KB. Uploads up to {$appMaxKb}KB will fail with a generic error before Laravel validation ever runs.");
            $ok = false;
        }

        if ($phpPostMaxBytes < $appMaxBytes) {
            $this->error('post_max_size is smaller than DOC_MAX_UPLOAD_KB.');
            $ok = false;
        }

        if ($ok) {
            $this->info('PHP upload limits are sufficient for the configured max document size.');
            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    private function toBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $num = (int) $value;

        return match ($unit) {
            'g' => $num * 1024 * 1024 * 1024,
            'm' => $num * 1024 * 1024,
            'k' => $num * 1024,
            default => (int) $value,
        };
    }
}
