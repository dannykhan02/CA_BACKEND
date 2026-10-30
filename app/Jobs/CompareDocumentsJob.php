<?php

namespace App\Jobs;

use App\Exceptions\ProviderBusyException;
use App\Jobs\Concerns\DefersWhenProviderBusy;
use App\Models\DocumentComparison;
use App\Models\OperationQuote;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\ProviderGate;
use App\Services\AiCredits\QuoteService;
use App\Services\AnthropicClient;
use App\Services\Comparisons\ComparisonExecutionLock;
use App\Services\Comparisons\ComparisonRecovery;
use App\Services\DocumentComparisonService;
use App\Services\EntitlementService;
use App\Services\IntelligenceAccess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CompareDocumentsJob implements ShouldQueue
{
    use DefersWhenProviderBusy, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $comparisonId, public ?string $dispatchToken = null) {}

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
        app(ComparisonExecutionLock::class)->run($this->comparisonId, function () use ($service) {
            $workspace = DocumentComparison::whereKey($this->comparisonId)->value('workspace_id');
            if (! $workspace) {
                return;
            }
            $item = DB::transaction(function () use ($workspace) {
                Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
                $item = DocumentComparison::whereKey($this->comparisonId)->lockForUpdate()->first();
                if (! $item || $item->status !== 'queued' || $item->dispatch_token !== $this->dispatchToken
                    || ($item->metadata['algorithm_version'] ?? null) !== DocumentComparisonService::ALGORITHM
                    || $item->provider_started_at !== null) {
                    return null;
                }
                // Recheck creator access before any paid work. A saved result is separately authorized on read.
                $user = User::find($item->created_by);
                if (! $user || $user->current_workspace_id !== $item->workspace_id) {
                    app(ComparisonRecovery::class)->fail($item, 'Source access changed. Create a comparison from the current workspace.');

                    return null;
                }
                try {
                    app(IntelligenceAccess::class)->document($user, $item->base_document_id);
                    app(IntelligenceAccess::class)->document($user, $item->compared_document_id);
                } catch (ModelNotFoundException|\Illuminate\Auth\Access\AuthorizationException|HttpException $error) {
                    app(ComparisonRecovery::class)->fail($item, 'Source access is no longer available.');

                    return null;
                }
                $item->update(['status' => 'processing']);

                return $item;
            }, 3);
            if (! $item) {
                return;
            }
            try {
                $changes = $service->changes($item->metadata['base'], $item->metadata['compared']);
                $metadata = $item->metadata;
                if (isset($metadata['ai_context'])) {
                    app(EntitlementService::class)->reserveComparison($item);
                    // This durable fence precedes the provider call. Crashes must never replay it.
                    $item->update(['provider_started_at' => now()]);
                    $client = app(AnthropicClient::class);
                    $quoteId = QuoteService::enabled() ? OperationQuote::where('kind', 'comparison')->where('resource_id', $item->id)->where('status', 'reserved')->value('id') : null;
                    $client->forOperation($quoteId, $item->created_by, $item->id);
                    $result = $client->compareDocumentIntelligence($metadata['ai_context'], $item->baseDocument, $metadata['prompt_version'] ?? null, $metadata['prompt_hash'] ?? null, $metadata['model'] ?? null);
                    $changes = [...$changes, ...$result['changes']];
                    $metadata['model'] = $result['model'];
                    $metadata['prompt_version'] = $result['prompt_version'];
                }
                DB::transaction(function () use ($item, $metadata, $changes) {
                    Workspace::whereKey($item->workspace_id)->lockForUpdate()->firstOrFail();
                    $locked = DocumentComparison::whereKey($item->id)->lockForUpdate()->first();
                    if (! $locked || $locked->status !== 'processing' || $locked->dispatch_token !== $this->dispatchToken) {
                        return;
                    }
                    app(EntitlementService::class)->settleComparison($locked);
                    $locked->update(['metadata' => $metadata, 'status' => 'completed', 'changes' => $changes,
                        'summary' => count($changes).' comparison observations. Review classifications and evidence in both documents.', 'error_message' => null]);
                }, 3);
            } catch (\Throwable $error) {
                // Fail while still owning the session exclusion; release is idempotent.
                $this->failCurrent($item->workspace_id);
                throw $error;
            }
        });
    }

    public function failed(\Throwable $e): void
    {
        app(ComparisonExecutionLock::class)->run($this->comparisonId, function () {
            $workspace = DocumentComparison::whereKey($this->comparisonId)->value('workspace_id');
            if ($workspace) {
                $this->failCurrent($workspace);
            }
        });
    }

    private function failCurrent(string $workspace): void
    {
        DB::transaction(function () use ($workspace) {
            Workspace::whereKey($workspace)->lockForUpdate()->firstOrFail();
            $item = DocumentComparison::whereKey($this->comparisonId)->lockForUpdate()->first();
            if (! $item || ! in_array($item->status, ['queued', 'processing'], true) || $item->dispatch_token !== $this->dispatchToken) {
                return;
            }
            app(ComparisonRecovery::class)->fail($item,
                $item->provider_started_at !== null ? 'AI request outcome requires operational review. Automatic replay is blocked.' : 'Comparison could not be completed. Please retry.');
        }, 3);
    }
}
