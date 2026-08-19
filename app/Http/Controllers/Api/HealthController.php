<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;

/**
 * Unauthenticated, read-only. Reports only healthy/unhealthy per service —
 * never credentials, connection strings, or exception messages. Failures
 * are logged server-side via report(), not returned to the caller.
 */
class HealthController extends Controller
{
    public function index(): JsonResponse
    {
        $services = [
            'database' => $this->checkDatabase(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'storage' => $this->checkStorage(),
        ];

        $healthy = ! in_array(false, $services, true);

        return response()->json([
            'success' => $healthy,
            'status' => $healthy ? 'healthy' : 'degraded',
            'services' => array_map(fn ($ok) => $ok ? 'healthy' : 'unhealthy', $services),
        ], $healthy ? 200 : 503);
    }

    private function checkDatabase(): bool
    {
        try {
            DB::select('SELECT 1');
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    private function checkRedis(): bool
    {
        try {
            Redis::connection()->ping();
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    private function checkQueue(): bool
    {
        try {
            Queue::connection()->size();
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }

    private function checkStorage(): bool
    {
        try {
            $disk = Storage::disk(config('filesystems.default'));
            $testPath = 'health-check-' . uniqid() . '.txt';
            $disk->put($testPath, 'ok');
            $disk->delete($testPath);
            return true;
        } catch (\Throwable $e) {
            report($e);
            return false;
        }
    }
}
