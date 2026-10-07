<?php

namespace App\Jobs\Concerns;

use App\Exceptions\ProviderBusyException;
use App\Support\QueueTopology;
use Illuminate\Support\Facades\Log;

/**
 * No global Anthropic permit: re-enqueue a delayed copy of this job (same queue, same
 * arguments, same remaining chain) and finish this delivery normally. Unlike release(),
 * this consumes no retry attempt, because nothing was attempted.
 *
 * Not for Bus::batch members: finishing the original would count as a completed batch job.
 */
trait DefersWhenProviderBusy
{
    protected function deferForProvider(ProviderBusyException $e, array $context = [], ?callable $onScheduled = null): void
    {
        $delay = $e->retryAfterSeconds + random_int(0, max(0, (int) config('document_intelligence.provider_gate.busy_retry_jitter_seconds')));
        $onScheduled?->__invoke($delay);
        $copy = clone $this;
        $copy->job = null;
        $copy->delay($delay);
        $copy->onQueue($this->queue ?? QueueTopology::for(static::class));
        dispatch($copy);
        // The copy carries the rest of any chain; this delivery must not also continue it.
        $this->chained = [];
        Log::info('Job deferred for provider capacity', [...$context, 'job' => class_basename($this),
            'queue' => $this->queue, 'reason' => $e->reason, 'delay_seconds' => $delay]);
    }
}
