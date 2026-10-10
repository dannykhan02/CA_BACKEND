<?php

namespace App\Jobs;

use App\Exceptions\ProviderBusyException;
use App\Jobs\Concerns\DefersWhenProviderBusy;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\ProviderGate;
use App\Services\Intelligence\B2\NarrativeSynthesizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * B2: the verified AI narrative over a document's Stage A evidence.
 *
 * Runs only after the document is already Ready, and never gates it. Failing, timing out, being
 * denied a provider permit or being denied budget all leave the document, its Stage A analysis and
 * its deterministic B1 Brief exactly as they were; only the narrative is absent.
 *
 * Timeout ordering: the provider HTTP timeout (60s, intelligence_v2.b2.timeout_seconds) < this
 * job's timeout (120s) < the provider-gate lease (240s) < the synthesis worker timeout (360s) <
 * the Redis retry_after (390s). So a hung request cannot outlive the job, a crashed job cannot
 * leave a permit held beyond its lease, and the worker is never reaped mid-attempt.
 */
class SynthesizeBriefNarrativeJob implements ShouldQueue
{
    use DefersWhenProviderBusy, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    public function __construct(public string $documentId) {}

    public function handle(NarrativeSynthesizer $synthesizer): void
    {
        if (! config('intelligence_v2.enabled') || ! config('intelligence_v2.b2.enabled')) {
            return;
        }
        $document = Document::find($this->documentId);
        // 'Ready' only. canGenerateIntelligence() refuses a provider call for anything else, and a
        // document in Needs Review has incomplete Stage A coverage, where B1 is the honest answer.
        if (! $document || $document->status !== 'Ready') {
            return;
        }
        try {
            // One permit for the whole attempt. Priority: B2 is completion work for a document the
            // reader is already looking at, so it must not queue behind bulk extraction forever —
            // but a denied permit simply defers, and B1 stays available in the meantime.
            app(ProviderGate::class)->hold($this->documentId,
                fn () => $synthesizer->synthesize($document),
                priority: true, waiter: 'brief_synthesis:'.$this->documentId);
        } catch (ProviderBusyException $e) {
            $this->deferForProvider($e, ['document_id' => $this->documentId]);
        }
    }

    public function failed(\Throwable $e): void
    {
        // Deliberately does not touch the document: B2 is additive and B1 is the fallback.
        //
        // A worker killed mid-attempt skips the synthesizer's own settlement, which would leave the
        // unit `running` forever — indistinguishable from a live attempt, so every later claim would
        // answer `in_flight` and B2 would never be retried for this evidence set. Mark it terminal
        // instead. The reservation stays committed, because the request may well have been paid for.
        $document = Document::find($this->documentId);
        if ($document && is_string($document->ai_pipeline['key'] ?? null)) {
            DocumentChunk::where('document_id', $document->id)
                ->where('pipeline_key', $document->ai_pipeline['key'])
                ->where('stage', 'brief_synthesis')->where('status', 'running')
                ->update(['status' => 'uncertain', 'failure_class' => 'worker_timeout',
                    'completed_at' => now()]);
        }
        Log::warning('docintel.v2.b2_job_failed', ['document_id' => $this->documentId,
            'error_type' => $e::class]);
    }
}
