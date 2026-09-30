<?php

namespace App\Console\Commands;

use App\Services\SubscriptionService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ReconcileBillingEvents extends Command
{
    protected $signature = 'billing:reconcile-events {--limit=100}';
    protected $description = 'Manually retry unresolved authenticated Paystack events';

    public function handle(SubscriptionService $service): int
    {
        $limit = min(1000, max(1, (int) $this->option('limit')));
        $events = DB::table('billing_webhook_events')->whereNull('processed_at')->orderBy('created_at')->limit($limit)->get();
        foreach ($events as $event) {
            try {
                $service->receive(json_decode($event->payload, true), true);
            } catch (\Throwable $error) {
                Log::warning('Manual billing event reconciliation failed.', ['billing_event_id' => $event->id, 'event_type' => $event->event_type,
                    'exception_type' => get_class($error)]);
            }
        }
        $this->info('Inspected '.$events->count().' unresolved events.');
        $this->table(['Status', 'Count', 'Oldest'], array_map(fn ($row) => [$row->status, $row->total, $row->oldest], DB::table('billing_webhook_events')
            ->whereNull('processed_at')->selectRaw('status, count(*) as total, min(created_at) as oldest')->groupBy('status')->get()->all()));

        return self::SUCCESS;
    }
}
