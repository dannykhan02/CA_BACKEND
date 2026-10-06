<?php

namespace App\Services\AI\ProviderGate;

/**
 * In-process twin of RedisGateStore for tests (same admission rules). State is static,
 * so separate gate instances share it like separate app instances share Redis.
 */
class MemoryGateStore
{
    private static array $leases = [];

    private static array $waiting = [];

    private static array $priority = [];

    private static array $counters = [];

    public static ?float $clock = null;

    public static function reset(): void
    {
        self::$leases = self::$waiting = self::$priority = self::$counters = [];
        self::$clock = null;
    }

    private function now(): int
    {
        return (int) ((self::$clock ?? microtime(true)) * 1000);
    }

    public function acquire(string $token, string $document, int $ttlMs, int $max, int $perDocument, bool $reserveForOthers, int $waiterMs,
        bool $priority = false, string $waiter = ''): array
    {
        $now = $this->now();
        $waiter = $waiter ?: $token;
        self::$priority = array_filter(self::$priority, fn ($expiry) => $expiry > $now);
        $expired = count(array_filter(self::$leases, fn ($expiry) => $expiry <= $now));
        self::$leases = array_filter(self::$leases, fn ($expiry) => $expiry > $now);
        self::$waiting = array_filter(self::$waiting, fn ($expiry) => $expiry > $now);
        if ($expired) {
            self::$counters['lease_expired'] = (self::$counters['lease_expired'] ?? 0) + $expired;
        }
        $active = count(self::$leases);
        $deny = function (string $reason) use ($document, $now, $waiterMs, $active, $token, $priority, $waiter) {
            if ($document !== '') {
                self::$waiting[$document] = $now + $waiterMs;
            }
            if ($priority) {
                self::$priority[$waiter] = $now + $waiterMs;
            }
            self::$counters['denied_'.$reason] = (self::$counters['denied_'.$reason] ?? 0) + 1;

            return [false, $reason, $active, $token.'|'.$document];
        };
        if ($active >= $max) {
            return $deny('global');
        }
        if (! $priority && self::$priority !== [] && $max - $active <= count(self::$priority)) {
            return $deny('priority');
        }
        if ($document !== '') {
            $holders = [];
            foreach (array_keys(self::$leases) as $member) {
                $holder = substr($member, strpos($member, '|') + 1);
                if ($holder !== '') {
                    $holders[$holder] = ($holders[$holder] ?? 0) + 1;
                }
            }
            $held = $holders[$document] ?? 0;
            if ($held >= $perDocument) {
                return $deny('document');
            }
            if ($held >= 1) {
                $waiters = count(array_filter(array_keys(self::$waiting), fn ($w) => $w !== $document && ! isset($holders[$w])));
                if ($max - $active <= max($reserveForOthers ? 1 : 0, $waiters)) {
                    return $deny('fairness');
                }
            }
            unset(self::$waiting[$document]);
        }
        if ($priority) {
            unset(self::$priority[$waiter]);
        }
        self::$leases[$token.'|'.$document] = $now + $ttlMs;
        self::$counters['acquired'] = (self::$counters['acquired'] ?? 0) + 1;

        return [true, 'ok', $active + 1, $token.'|'.$document];
    }

    public function withdraw(string $waiter): void
    {
        unset(self::$priority[$waiter]);
    }

    public function release(string $member): void
    {
        unset(self::$leases[$member]);
    }

    public function stats(): array
    {
        return ['active' => count(self::$leases), 'waiting_documents' => count(self::$waiting),
            'priority_waiters' => count(self::$priority), 'counters' => self::$counters];
    }
}
