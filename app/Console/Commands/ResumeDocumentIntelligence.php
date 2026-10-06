<?php

namespace App\Console\Commands;

use App\Jobs\ResumeAfterCreditConfirmationJob;
use App\Models\Document;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AI\Incremental\VisualPlanner;
use App\Services\AiCredits\QuoteService;
use App\Support\QueueTopology;
use Illuminate\Console\Command;

class ResumeDocumentIntelligence extends Command
{
    protected $signature = 'docintel:resume {document?}';

    protected $description = 'Recover interrupted incremental scheduling without replaying ambiguous provider calls';

    public function handle(IncrementalPipeline $pipeline): int
    {
        $query = Document::whereIn('status', ['Processing', 'Ready'])->where(fn ($q) => $q->where(fn ($incremental) => $incremental->where('ai_pipeline->route', 'incremental')->where(fn ($active) => $active->whereNull('ai_pipeline->recovery_complete')->orWhere('ai_pipeline->recovery_complete', false)->orWhere('status', 'Processing')))->orWhereHas('processingChunks', fn ($units) => $units->whereIn('stage', ['visual', 'visual_plan'])->whereIn('status', ['pending', 'queued', 'running'])));
        if ($this->argument('document')) {
            $query->whereKey($this->argument('document'));
        }
        $this->recoverCreditConfirmations();
        $query->chunkById(50, function ($documents) use ($pipeline) {
            foreach ($documents as $document) {
                if (($document->ai_pipeline['route'] ?? null) === 'incremental') {
                    $pipeline->recover($document);
                }
                app(VisualPlanner::class)->recover($document);
            }
        });

        return self::SUCCESS;
    }

    /**
     * A document waiting on a credit confirmation has no pipeline route yet, so the recovery above never sees it.
     * Re-dispatch the resume when the customer already confirmed (the queue message was lost), or when the flag was
     * turned off while it waited (admission then lets it proceed under the legacy rules). Never confirms for anyone.
     */
    private function recoverCreditConfirmations(): void
    {
        if ($this->argument('document')) {
            return;
        }
        $enabled = QuoteService::enabled();
        Document::where('status', 'Processing')->where('ai_pipeline->awaiting_credit_confirmation', true)
            ->where('updated_at', '<=', now()->subMinutes(10))
            ->when($enabled, fn ($q) => $q->where('ai_pipeline->credit_quote_confirmed', true))
            ->select('id')->chunkById(50, function ($documents) {
                foreach ($documents as $document) {
                    ResumeAfterCreditConfirmationJob::dispatch($document->id)->onQueue(QueueTopology::for(ResumeAfterCreditConfirmationJob::class));
                }
            });
    }
}
