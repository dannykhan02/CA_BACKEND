<?php

namespace App\Services\Comparisons;

use App\Jobs\CompareDocumentsJob;
use App\Models\DocumentComparison;
use App\Models\Workspace;
use App\Services\DocumentComparisonService;
use App\Services\EntitlementService;
use App\Support\QueueTopology;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ComparisonRecovery
{
    // Redelivery throttle, never a declaration that an active job has timed out.
    public const REDELIVERY_SECONDS = 300;

    public function reconcile(string $id): void
    {
        app(ComparisonExecutionLock::class)->run($id, function () use ($id) {
            $workspace = DocumentComparison::whereKey($id)->value('workspace_id');
            if (! $workspace) {
                return;
            }
            DB::transaction(function () use ($id, $workspace) {
                Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
                $item = DocumentComparison::whereKey($id)->lockForUpdate()->first();
                if (! $item || ! in_array($item->status, ['queued', 'processing'], true)) {
                    return;
                }
                if (($item->metadata['algorithm_version'] ?? null) !== DocumentComparisonService::ALGORITHM) {
                    if ($item->status === 'processing' && isset($item->metadata['ai_context'])) {
                        $item->provider_started_at = $item->provider_started_at ?? now();
                    }
                    $this->fail($item, 'Historical pending comparison cannot be replayed with a different algorithm. Create a new comparison.');

                    return;
                }
                if ($item->provider_started_at !== null) {
                    $this->fail($item, 'AI request outcome requires operational review. Automatic replay is blocked.');

                    return;
                }
                if ($item->status === 'queued' && ($item->dispatched_at ?? $item->created_at)->gt(now()->subSeconds(self::REDELIVERY_SECONDS))) {
                    return;
                }
                $this->dispatch($item);
            }, 3);
        });
    }

    /** Caller holds workspace + comparison row locks. Does not reserve or call AI. */
    public function dispatch(DocumentComparison $item): void
    {
        $item->update(['dispatch_token' => (string) Str::uuid(), 'dispatched_at' => now(), 'status' => 'queued', 'error_message' => null]);
        CompareDocumentsJob::dispatch($item->id, $item->dispatch_token)
            ->onQueue(QueueTopology::for(CompareDocumentsJob::class))->afterCommit();
    }

    public function fail(DocumentComparison $item, string $message): void
    {
        app(EntitlementService::class)->releaseComparison($item);
        $item->update(['status' => 'failed', 'error_message' => $message]);
    }
}
