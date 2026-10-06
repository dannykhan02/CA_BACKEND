<?php

namespace App\Jobs;

use App\Jobs\Concerns\DispatchesIntelligenceChain;
use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Continues a document whose customer accepted a large credit quote; admission reserves the credits first. */
class ResumeAfterCreditConfirmationJob implements ShouldQueue
{
    use Dispatchable, DispatchesIntelligenceChain, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public string $documentId) {}

    public function handle(): void
    {
        if ($document = Document::find($this->documentId)) {
            $this->dispatchIntelligenceChain($document);
        }
    }
}
