<?php

namespace App\Jobs;

use App\Jobs\Concerns\DispatchesIntelligenceChain;
use App\Models\Document;
use App\Support\QueueTopology;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/** Continues a document whose customer accepted a large credit quote; admission reserves the credits first. */
class ResumeAfterCreditConfirmationJob implements ShouldQueue
{
    use Dispatchable, DispatchesIntelligenceChain, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public ?string $revisionId = null;

    public function __construct(public string $documentId) {}

    public function handle(): void
    {
        $document = Document::find($this->documentId);
        if (! $document) {
            return;
        }
        // Same revision the upload chain carried, so the batch and the insights stage agree with it.
        $this->revisionId = $document->active_intelligence_revision_id;
        // Admission reserves the credits (the confirmation is recorded) and clears the waiting flag.
        $this->dispatchIntelligenceChain($document, $this->revisionId === null);

        // The upload chain's insights stage stepped aside while the quote was unconfirmed. Run it now, once: the
        // marker is cleared in the same statement that decides, so a repeated resume cannot start it twice.
        $fresh = $document->fresh();
        if ($fresh?->status !== 'Processing' || ($fresh->ai_pipeline['awaiting_credit_confirmation'] ?? false)) {
            return;
        }
        $held = DB::update("UPDATE documents SET ai_pipeline = ai_pipeline - 'insights_held' WHERE id = ? AND ai_pipeline->>'insights_held' = 'true'", [$fresh->id]);
        if ($held > 0 && ($fresh->ai_pipeline['route'] ?? null) !== 'incremental') {
            GenerateInsightsJob::dispatch($fresh->id, false, $this->revisionId)->onQueue(QueueTopology::for(GenerateInsightsJob::class));
        }
    }
}
