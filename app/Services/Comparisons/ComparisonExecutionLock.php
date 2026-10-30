<?php

namespace App\Services\Comparisons;

use Illuminate\Support\Facades\DB;

/** Session lifetime proves liveness without expiring an active worker's claim. */
class ComparisonExecutionLock
{
    public function run(string $id, callable $work): mixed
    {
        if (DB::getDriverName() !== 'pgsql') {
            throw new \LogicException('Comparison execution requires session-affine PostgreSQL.');
        }
        $connection = DB::connection();
        $connection->reconnectIfMissingConnection();
        $pdo = $connection->getPdo();
        $lock = $pdo->prepare("SELECT pg_try_advisory_lock(hashtext('docintel-comparison'), hashtext(?))");
        $lock->execute([$id]);
        if (! $lock->fetchColumn()) {
            return null;
        }
        try {
            return $work();
        } finally {
            $unlock = $pdo->prepare("SELECT pg_advisory_unlock(hashtext('docintel-comparison'), hashtext(?))");
            $unlock->execute([$id]);
        }
    }
}
