<?php

namespace App\Services\AI;

use App\Exceptions\ProviderBusyException;
use App\Services\AI\ProviderGate\MemoryGateStore;
use App\Services\AI\ProviderGate\RedisGateStore;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Global cap on simultaneous Anthropic HTTP calls across every process, worker replica
 * and web request (ANTHROPIC_MAX_INFLIGHT), enforced atomically in shared Redis.
 *
 * - A permit is a lease with a TTL: a crashed holder's permit expires; nothing leaks forever.
 * - Per document: at most document_intelligence.concurrency permits, and a document that
 *   already holds one cannot take the last free permit while another document is waiting.
 * - call() guards one HTTP request (each retry/repair re-acquires). hold() pre-admits a
 *   whole job so its calls reuse the permit and it can be deferred before claiming work.
 *
 * Horizon process counts multiply per replica; this cap does not.
 */
class ProviderGate
{
    private ?string $ambient = null;

    private ?MemoryGateStore $memory = null;

    public function max(): int
    {
        return max(1, (int) config('document_intelligence.provider_gate.max_inflight'));
    }

    public function store(): RedisGateStore|MemoryGateStore
    {
        return config('document_intelligence.provider_gate.driver') === 'memory'
            ? ($this->memory ??= new MemoryGateStore)
            : new RedisGateStore((string) config('document_intelligence.provider_gate.connection'), (string) config('document_intelligence.provider_gate.prefix'));
    }

    /** Run a whole unit of provider work under one permit, or throw ProviderBusyException immediately. */
    /**
     * $priority: completion/interactive work (synthesis). A denied priority caller is owed the
     * next free permit, so bulk extraction cannot keep it waiting indefinitely; $waiter keeps
     * that claim stable across the deferred re-delivery.
     */
    public function hold(?string $documentId, callable $work, bool $reserveForOthers = false, bool $priority = false, ?string $waiter = null): mixed
    {
        if ($this->ambient !== null) {
            return $work();
        }
        $member = $this->acquire($documentId, 0.0, $reserveForOthers, $priority, $waiter);
        $this->ambient = $member;
        try {
            return $work();
        } finally {
            $this->ambient = null;
            $this->releaseQuietly($member);
        }
    }

    /** Guard one provider HTTP request. Reuses a held permit; otherwise waits briefly (bounded). */
    public function call(callable $request, ?string $documentId = null, ?float $waitSeconds = null): mixed
    {
        if ($this->ambient !== null) {
            return $request();
        }
        $interactive = ! app()->runningInConsole();
        $wait = $waitSeconds ?? (float) config($interactive
            ? 'document_intelligence.provider_gate.web_wait_seconds' : 'document_intelligence.provider_gate.job_wait_seconds');
        // A user waiting on a web request (Q&A) is served ahead of bulk background work.
        $member = $this->acquire($documentId, $wait, false, $interactive, null);
        try {
            return $request();
        } finally {
            $this->releaseQuietly($member);
        }
    }

    /**
     * A permit is a lease that expires on its own. If Redis is unreachable at release time the work has already
     * run (and been paid for), so the failure must not replace its result or lose its usage record.
     */
    private function releaseQuietly(string $member): void
    {
        try {
            $this->store()->release($member);
        } catch (\Throwable $e) {
            Log::warning('Anthropic permit release failed; the lease will expire on its own', ['error_type' => $e::class]);
        }
    }

    public function holding(): bool
    {
        return $this->ambient !== null;
    }

    /** Metadata only: configured max, active permits, admission counters. */
    public function snapshot(): array
    {
        return ['max_inflight' => $this->max(), 'lease_seconds' => (int) config('document_intelligence.provider_gate.lease_seconds'),
            ...$this->store()->stats()];
    }

    private function acquire(?string $documentId, float $waitSeconds, bool $reserveForOthers, bool $priority = false, ?string $waiter = null): string
    {
        $returning = $waiter !== null; // A deferred job comes back for its claim; a web request does not.
        $waiter ??= (string) Str::uuid(); // Stable across this call's polls.
        $config = config('document_intelligence.provider_gate');
        $started = hrtime(true);
        $deadline = microtime(true) + max(0.0, $waitSeconds);
        $store = $this->store();
        $polls = 0;
        while (true) {
            [$ok, $reason, $active, $member] = $store->acquire((string) Str::uuid(), (string) $documentId,
                (int) $config['lease_seconds'] * 1000, $this->max(), max(1, (int) config('document_intelligence.concurrency')),
                $reserveForOthers, (int) $config['waiter_seconds'] * 1000, $priority, $waiter);
            if ($ok) {
                if ($polls > 0) {
                    Log::info('Anthropic admission granted after wait', ['document_id' => $documentId,
                        'wait_ms' => (int) ((hrtime(true) - $started) / 1e6), 'active' => $active, 'max_inflight' => $this->max()]);
                }

                return $member;
            }
            if (microtime(true) >= $deadline) {
                if ($priority && ! $returning) {
                    $store->withdraw($waiter); // Nobody will come back for this claim.
                }
                Log::info('Anthropic admission deferred', ['document_id' => $documentId, 'reason' => $reason,
                    'active' => $active, 'max_inflight' => $this->max(), 'wait_ms' => (int) ((hrtime(true) - $started) / 1e6)]);

                throw new ProviderBusyException($reason, (int) $config['busy_retry_seconds']);
            }
            $polls++;
            // Bounded, jittered polling; never a tight spin.
            usleep(random_int(150, 400) * 1000);
        }
    }
}
