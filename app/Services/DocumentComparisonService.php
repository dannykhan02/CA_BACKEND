<?php

namespace App\Services;

use App\Jobs\CompareDocumentsJob;
use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentComparison;
use App\Models\User;
use App\Services\AI\PromptManager;
use Illuminate\Support\Facades\DB;

class DocumentComparisonService
{
    public function __construct(private IntelligenceAccess $access) {}

    public function snapshot(Document $document): array
    {
        $document->loadMissing(['deadlines', 'risks', 'entities', 'kpis']);
        $result = [];
        foreach (['deadlines' => 'title', 'risks' => 'title', 'entities' => 'entity_type', 'kpis' => 'label'] as $kind => $label) {
            foreach ($document->$kind as $item) {
                $value = match ($kind) {
                    'deadlines' => ['description' => $item->description, 'due_date' => $item->due_date?->toDateString(), 'relative_text' => $item->relative_text, 'date_type' => $item->date_type],
                    'risks' => ['description' => $item->description, 'severity' => $item->severity],
                    'entities' => ['value' => $item->normalized_value ?: $item->value],
                    'kpis' => ['value' => $item->value, 'unit' => $item->unit],
                };
                $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $item->$label)));
                $key = $kind.':'.($kind === 'kpis' ? $item->identityKey($normalized) : $normalized);
                $result[$key][] = ['category' => $kind, 'label' => $item->$label, 'value' => $value,
                    'source' => ['document_id' => $document->id, 'document_name' => $document->name,
                        'insight_id' => $item->id, 'evidence' => $item->evidence ?? $item->context]];
            }
        }
        ksort($result);
        foreach ($result as &$items) {
            usort($items, fn ($a, $b) => strcmp(json_encode($a['value']), json_encode($b['value'])));
        }

        return $result;
    }

    public function create(User $user, string $baseId, string $comparedId, bool $includeTerms = false): DocumentComparison
    {
        $base = $this->access->document($user, $baseId, true);
        $new = $this->access->document($user, $comparedId, true);
        abort_if($base->id === $new->id, 422, 'Choose two different documents.');
        foreach ([$base, $new] as $document) {
            abort_unless(in_array($document->status, ['Ready', 'Needs Review'], true), 422, 'Both documents must finish processing first.');
            $stages = app(DocumentIntelligenceService::class)->getProcessingStatus($document);
            foreach (['entities', 'risks', 'deadlines'] as $stage) {
                if (($stages[$stage] ?? null) === 'skipped') {
                    $stages[$stage] = $document->processingJobs()->where('stage', $stage)->where('status', '!=', 'skipped')->latest('created_at')->orderByDesc('id')->value('status');
                }
                abort_unless(in_array($stages[$stage] ?? null, ['completed', 'skipped'], true), 422, 'Structured extraction must complete successfully for both documents before comparison.');
            }
        }
        $before = $this->snapshot($base);
        $after = $this->snapshot($new);
        $metadata = ['method' => 'structured-v1', 'base' => $before, 'compared' => $after,
            'limitations' => 'Compares extracted intelligence, not every clause. Missing extraction is not proof a term was removed. Confirm changes in both originals; no legal authority is inferred.'];
        if ($includeTerms) {
            abort_unless(AiPrompt::where('name', 'document_comparison')->where('active', true)->exists(), 503, 'Terms comparison is not configured. Activate the document_comparison prompt.');
            $contextService = app(ComparisonContextService::class);
            $metadata['method'] = 'structured-and-terms-v1';
            $metadata['ai_context'] = [];
            foreach (['base' => $base, 'compared' => $new] as $side => $document) {
                $excerpts = $contextService->excerpts($document);
                abort_if(! $excerpts, 422, 'Document text is required for a terms comparison.');
                $metadata['ai_context'][$side] = ['document_id' => $document->id, 'document_name' => $document->name,
                    'excerpts' => $excerpts];
            }
            $metadata['prompt_version'] = app(PromptManager::class)->resolve('document_comparison')->version;
            $metadata['model'] = config('services.anthropic.model');
            $metadata['limitations'] .= ' Optional AI terms analysis uses at most 12 selected excerpts per document and may miss changes elsewhere.';
        }

        return DB::transaction(function () use ($user, $base, $new, $metadata) {
            $comparison = DocumentComparison::firstOrCreate([
                'workspace_id' => $user->current_workspace_id, 'base_document_id' => $base->id,
                'compared_document_id' => $new->id, 'fingerprint' => hash('sha256', json_encode($metadata)),
            ], ['created_by' => $user->id, 'matter_id' => $base->matter_id === $new->matter_id ? $base->matter_id : null, 'metadata' => $metadata]);
            if ($comparison->wasRecentlyCreated || $comparison->status === 'failed') {
                app(EntitlementService::class)->reserveComparison($comparison, true);
                $comparison->update(['status' => 'queued', 'error_message' => null]);
                CompareDocumentsJob::dispatch($comparison->id)->onQueue('extraction')->afterCommit();
            }

            return $comparison;
        });
    }

    public function changes(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? [];
            $new = $after[$key] ?? [];
            if (array_column($old, 'value') === array_column($new, 'value')) {
                continue;
            }
            $reference = $new[0] ?? $old[0];
            $changes[] = ['category' => $reference['category'], 'label' => $reference['label'],
                'description' => ! $old ? 'Newly observed in extracted intelligence' : (! $new ? 'Not observed in compared extraction' : 'Changed extracted value or state'),
                'before' => $old, 'after' => $new];
        }

        return $changes;
    }
}
