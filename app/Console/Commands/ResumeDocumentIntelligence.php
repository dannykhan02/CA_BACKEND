<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Services\AI\Incremental\IncrementalPipeline;
use App\Services\AI\Incremental\VisualPlanner;
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
}
