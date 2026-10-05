<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Services\AI\Incremental\ContextResolver;
use App\Services\AI\Incremental\EvidenceMerger;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceCreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class MergeDocumentEvidenceJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public string $chunkId) {}

    public function handle(EvidenceMerger $merger, PipelineStageRecorder $recorder): void
    {
        $unit = DocumentChunk::find($this->chunkId);
        $document = $unit?->document;
        if (! $document?->canGenerateIntelligence() || $unit->workspace_id !== $document->workspace_id
            || $unit->pipeline_key !== ($document->ai_pipeline['key'] ?? null)) {
            return;
        }
        // Merge is deterministic and idempotent; repeating it has no provider cost.
        if ($unit->status === 'completed') {
            return;
        }
        $unit->increment('attempts');
        $merger->merge($document);
        app(ContextResolver::class)->resolve($document);
        DB::transaction(function () use ($unit, $document, $recorder) {
            $locked = Document::whereKey($document->id)->lockForUpdate()->firstOrFail();
            $unit->refresh();
            if ($unit->status === 'completed' || ! $locked->canGenerateIntelligence()) {
                return;
            }
            foreach (['entities', 'risks', 'deadlines', 'ai_analysis'] as $stage) {
                $recorder->complete($recorder->start($locked, $stage), ['pipeline_key' => $unit->pipeline_key, 'incremental' => true]);
            }
            $recorder->skip($locked, 'document_type', 'Global evidence extraction does not require classification.');
            app(WorkspaceCreditService::class)->accountForReadyDocument($locked);
            $locked->forceFill(['status' => 'Ready', 'progress' => 100, 'has_structured_data' => $locked->kpis()->exists()])->save();
            $unit->update(['status' => 'completed', 'completed_at' => now()]);
            GenerateDocumentSummaryJob::dispatch($locked->id)->onQueue('extraction')->afterCommit();
            AnalyzeEmbeddedVisualsJob::dispatch($locked->id)->onQueue('extraction')->afterCommit();
            GenerateEmbeddingsJob::dispatch($locked->id)->onQueue('extraction')->afterCommit();
        });
    }
}
