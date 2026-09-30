<?php

namespace App\Jobs;

use App\Services\SubscriptionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RetryDeferredBillingEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 6;
    public int $timeout = 30;

    public function __construct(public int $eventId) {}

    public function backoff(): array
    {
        return [60, 300, 900, 3600, 10800];
    }

    public function handle(SubscriptionService $service): void
    {
        // receive() performs provider verification before locking. Inspect a
        // locked row first, then release it before that external request.
        $event = DB::transaction(fn () => DB::table('billing_webhook_events')->where('id', $this->eventId)->lockForUpdate()->first());
        if (! $event || $event->processed_at || $event->status === 'requires_review') {
            return;
        }

        DB::table('billing_webhook_events')->where('id', $event->id)->whereNull('processed_at')->update([
            'status' => 'retrying', 'last_attempted_at' => now(), 'retry_count' => DB::raw('retry_count + 1'),
        ]);
        try {
            $service->receive(json_decode($event->payload, true), true);
        } catch (\Throwable $error) {
            Log::warning('Deferred billing event retry failed.', ['billing_event_id' => $event->id, 'event_type' => $event->event_type,
                'exception_type' => get_class($error)]);
            DB::table('billing_webhook_events')->where('id', $event->id)->whereNull('processed_at')->update([
                'status' => 'failed', 'deferral_reason' => 'processing_error',
            ]);
        }
        $current = DB::table('billing_webhook_events')->where('id', $event->id)->first();
        if (! $current || $current->processed_at) {
            return;
        }
        if ($this->attempts() >= $this->tries) {
            DB::table('billing_webhook_events')->where('id', $event->id)->whereNull('processed_at')->update([
                'status' => 'requires_review', 'requires_review_at' => now(),
            ]);
            Log::warning('Deferred billing event requires review.', ['billing_event_id' => $event->id, 'event_type' => $event->event_type]);
            return;
        }
        $this->release($this->backoff()[min($this->attempts() - 1, 4)]);
    }
}
