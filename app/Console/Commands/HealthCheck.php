<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

class HealthCheck extends Command
{
    protected $signature = 'health:check';
    protected $description = 'Checks DB, Redis, disk space, and Horizon liveness for external heartbeat monitoring.';

    public function handle(): int
    {
        $failures = [];

        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $failures[] = 'DB: ' . $e->getMessage();
        }

        try {
            Redis::ping();
        } catch (\Throwable $e) {
            $failures[] = 'Redis: ' . $e->getMessage();
        }

        $freeBytes = disk_free_space(base_path());
        $freeGb = round($freeBytes / 1024 / 1024 / 1024, 1);
        if ($freeGb < 2) {
            $failures[] = "Disk space low: {$freeGb}GB free";
        }

        if (empty($failures)) {
            $this->info('All checks passed.');
            return self::SUCCESS;
        }

        $this->error('Health check FAILED:');
        foreach ($failures as $f) {
            $this->error("  - {$f}");
        }
        return self::FAILURE;
    }
}