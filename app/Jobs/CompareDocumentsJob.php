<?php

namespace App\Jobs;

use App\Exceptions\ProviderBusyException;
use App\Jobs\Concerns\DefersWhenProviderBusy;
use App\Models\DocumentComparison;
use App\Models\OperationQuote;
use App\Models\Workspace;
use App\Services\AI\ProviderGate;
use App\Services\AiCredits\QuoteService;
use App\Services\AnthropicClient;
use App\Services\DocumentComparisonService;
use App\Services\EntitlementService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class CompareDocumentsJob implements ShouldQueue
{
    use DefersWhenProviderBusy, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public function __construct(public string $comparisonId) {}

    public function middleware(): array
    {
        return [(new WithoutOverlapping('comparison:'.$this->comparisonId))->releaseAfter(10)->expireAfter(180)];
    }

    public function handle(DocumentComparisonService $service): void
    {
        // Only a terms comparison calls Anthropic; it waits for a global permit before reserving.
        if (! isset(DocumentComparison::find($this->comparisonId)?->metadata['ai_context'])) {
            $this->process($service);

            return;
        }
        try {
            app(ProviderGate::class)->hold(null, fn () => $this->process($service));
        } catch (ProviderBusyException $e) {
            $this->deferForProvider($e, ['comparison_id' => $this->comparisonId]);
        }
    }

    private function process(DocumentComparisonService $service): void
    {
        $item = DocumentComparison::with(['baseDocument', 'comparedDocument'])->find($this->comparisonId);
        if (! $item || $item->status === 'completed') {
            return;
        }
        if (! $item->baseDocument || ! $item->comparedDocument) {
            app(EntitlementService::class)->releaseComparison($item);
            $item->delete();

            return;
        }
        $item->update(['status' => 'processing']);
        $changes = $service->changes($item->metadata['base'], $item->metadata['compared']);
        $metadata = $item->metadata;
        if (isset($metadata['ai_context'])) {
            app(EntitlementService::class)->reserveComparison($item);
            $client = app(AnthropicClient::class);
            if (QuoteService::enabled()) {
                // Durable attribution: every paid call is stamped with this comparison and its quote.
                $quoteId = OperationQuote::where('kind', 'comparison')->where('resource_id', $item->id)->where('status', 'reserved')->value('id');
                $client->forOperation($quoteId, $item->created_by, $item->id);
            }
            $result = $client->compareDocumentIntelligence($metadata['ai_context'], $item->baseDocument);
            $changes = [...$changes, ...$result['changes']];
            $metadata['model'] = $result['model'];
            $metadata['prompt_version'] = $result['prompt_version'];
        }
        DB::transaction(function () use ($item, $metadata, $changes) {
            Workspace::whereKey($item->workspace_id)->lockForUpdate()->firstOrFail();
            $locked = DocumentComparison::whereKey($item->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'completed') {
                return;
            }
            app(EntitlementService::class)->settleComparison($locked);
            $locked->update(['metadata' => $metadata, 'status' => 'completed', 'changes' => $changes,
                'summary' => count($changes).' changes observed in extracted intelligence. Review the evidence in both documents.', 'error_message' => null]);
        }, 3);
    }

    public function failed(\Throwable $e): void
    {
        $workspaceId = DocumentComparison::whereKey($this->comparisonId)->value('workspace_id');
        if (! $workspaceId) {
            return;
        }
        DB::transaction(function () use ($workspaceId) {
            Workspace::whereKey($workspaceId)->lockForUpdate()->firstOrFail();
            $item = DocumentComparison::whereKey($this->comparisonId)->lockForUpdate()->first();
            if (! $item || $item->status === 'completed') {
                return;
            }
            app(EntitlementService::class)->releaseComparison($item);
            $item->update(['status' => 'failed', 'error_message' => 'Comparison could not be completed. Please retry.']);
        }, 3);
    }
}
