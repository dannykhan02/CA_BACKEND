<?php

namespace App\Services\Pipeline;

use App\Models\Document;
use App\Models\ProcessingJob;
use Illuminate\Support\Facades\DB;

class ProcessingStageReconciler
{
    private const OPTIONAL_STAGES = ['document_type', 'entities', 'risks', 'deadlines', 'document_summary'];

    // Optional jobs have bounded timeouts (entity extraction is the longest
    // at 330 seconds). Allow room for retries and clock skew before expiry.
    public const PROCESSING_GRACE_MINUTES = 15;

    // A queued retry may wait for a busy worker. Only expire an unclaimed
    // pending attempt after a substantially longer grace period.
    public const PENDING_GRACE_MINUTES = 60;

    public function reconcile(Document $document): void
    {
        DB::transaction(function () use ($document) {
            Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            foreach (self::OPTIONAL_STAGES as $stage) {
                $attempts = ProcessingJob::where('document_id', $document->id)
                    ->where('stage', $stage)->orderByDesc('created_at')->orderByDesc('id')
                    ->lockForUpdate()->get();
                $latest = $attempts->first();
                foreach ($attempts->skip(1) as $older) {
                    if (in_array($older->status, ['pending', 'processing'], true)) {
                        $older->forceFill(['status' => 'skipped', 'completed_at' => now(),
                            'output' => ['reason' => 'Superseded by a newer attempt.']])->save();
                    }
                }
                if (! $latest) {
                    continue;
                }
                if ($stage === 'document_summary' && $latest->status === 'completed'
                    && ! $document->intelligenceSummary()->exists()) {
                    $latest->forceFill(['status' => 'failed', 'completed_at' => now(),
                        'error_message' => 'Summary result is missing. Please retry this section.'])->save();

                    continue;
                }
                $started = $latest->status === 'pending'
                    ? $latest->created_at : ($latest->started_at ?? $latest->created_at);
                $minutes = $latest->status === 'pending'
                    ? self::PENDING_GRACE_MINUTES : self::PROCESSING_GRACE_MINUTES;
                if (in_array($latest->status, ['pending', 'processing'], true)
                    && $started?->lte(now()->subMinutes($minutes))) {
                    $latest->forceFill(['status' => 'failed', 'completed_at' => now(),
                        'error_message' => 'Analysis attempt expired before completion. Please retry this section.'])->save();
                }
            }
        });
    }
}
