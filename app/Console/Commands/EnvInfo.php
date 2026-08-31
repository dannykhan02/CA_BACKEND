<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Redis;

/**
 * Read-only diagnostic reporting the facts that would have caught both
 * environment confusion incidents from the last session immediately: a
 * server process resolving to the wrong DB_* values, and Horizon writing
 * to a Redis prefix nobody was looking at. This is NOT a gate — exits 0
 * always. config:check-production-safety already exists as the hard gate;
 * this command is for a human to eyeball, e.g. as the first step of
 * scripts/smoke-test.sh and scripts/provision.sh.
 *
 * Usage: php artisan env:info
 */
class EnvInfo extends Command
{
    protected $signature = 'env:info';
    protected $description = 'Report resolved DB, Redis, and app environment config for human inspection.';

    public function handle(): int
    {
        $this->line('=== Database ===');
        $connection = config('database.default');
        $this->line("Connection name: {$connection}");
        $this->line('Host:            ' . config("database.connections.{$connection}.host"));
        $this->line('Port:            ' . config("database.connections.{$connection}.port"));
        $this->line('Database:        ' . config("database.connections.{$connection}.database"));

        $this->newLine();
        $this->line('=== Redis ===');
        $this->line('Host:            ' . config('database.redis.default.host'));
        $this->line('Port:            ' . config('database.redis.default.port'));
        $this->line('Options prefix:  ' . config('database.redis.options.prefix'));
        $this->line('Horizon prefix:  ' . config('horizon.prefix'));

        try {
            $pong = Redis::connection()->ping();
            $this->line('Ping:            OK (' . $pong . ')');
        } catch (\Throwable $e) {
            $this->error('Ping:            FAILED - ' . $e->getMessage());
        }

        $this->newLine();
        $this->line('=== App ===');
        $this->line('app.env:         ' . config('app.env'));
        $this->line('app.debug:       ' . (config('app.debug') ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
