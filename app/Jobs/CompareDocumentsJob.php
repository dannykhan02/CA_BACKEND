<?php

namespace App\Jobs;

use App\Models\DocumentComparison;
use App\Services\AnthropicClient;
use App\Services\DocumentComparisonService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class CompareDocumentsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public string $comparisonId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('comparison:'.$this->comparisonId))->releaseAfter(10)->expireAfter(180)];
    }

    public function handle(DocumentComparisonService $service): void
    {
        $item = DocumentComparison::with(['baseDocument', 'comparedDocument'])->find($this->comparisonId);
        if (! $item || $item->status === 'completed') {
            return;
        }
        if (! $item->baseDocument || ! $item->comparedDocument) {
            $item->delete();

            return;
        }
        $item->update(['status' => 'processing']);
        $changes = $service->changes($item->metadata['base'], $item->metadata['compared']);
        $metadata = $item->metadata;
        if (isset($metadata['ai_context'])) {
            $result = app(AnthropicClient::class)->compareDocumentIntelligence($metadata['ai_context'], $item->baseDocument);
            $changes = [...$changes, ...$result['changes']];
            $metadata['model'] = $result['model'];
            $metadata['prompt_version'] = $result['prompt_version'];
        }
        $item->update(['metadata' => $metadata, 'status' => 'completed', 'changes' => $changes,
            'summary' => count($changes).' changes observed in extracted intelligence. Review the evidence in both documents.', 'error_message' => null]);
    }

    public function failed(\Throwable $e): void
    {
        DocumentComparison::whereKey($this->comparisonId)->update(['status' => 'failed', 'error_message' => 'Comparison failed. Please retry.']);
    }
}
