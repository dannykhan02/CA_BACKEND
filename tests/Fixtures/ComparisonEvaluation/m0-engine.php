<?php

namespace App\Services;

use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentComparison;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\PromptManager;
use App\Services\Comparisons\ComparisonRecovery;
use App\Services\Intelligence\CrossDocumentNumericDecision;
use App\Services\Intelligence\MetricCollector;
use Illuminate\Support\Facades\DB;

class M0DocumentComparisonService
{
    public const ALGORITHM = 'structured-m0-v2';

    public function __construct(private IntelligenceAccess $access) {}

    public function snapshot(Document $document): array
    {
        $document = app(DocumentIntelligenceService::class)->publishedView($document);
        // Eager loading uses a fresh Document instance and can lose the transient revision pin.
        foreach (['deadlines', 'risks', 'entities', 'kpis'] as $relation) {
            $document->setRelation($relation, $document->$relation()->where('workspace_id', $document->workspace_id)->get());
        }
        $observations = collect(app(MetricCollector::class)->collect($document)['observations'])->keyBy('sourceId');
        $result = [];
        foreach (['deadlines' => 'title', 'risks' => 'title', 'entities' => 'entity_type', 'kpis' => 'label'] as $kind => $label) {
            foreach ($document->$kind as $item) {
                $value = match ($kind) {
                    'deadlines' => ['description' => $item->description, 'due_date' => $item->due_date?->toDateString(), 'relative_text' => $item->relative_text, 'date_type' => $item->date_type],
                    'risks' => ['description' => $item->description, 'severity' => $item->severity],
                    'entities' => ['value' => $item->normalized_value ?: $item->value],
                    'kpis' => ['value' => $item->value, 'unit' => $item->unit, 'period' => $item->period],
                };
                $normalized = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $item->$label)));
                $observation = $kind === 'kpis' ? $observations->get('kpi:'.$item->id) : null;
                $context = $kind === 'kpis' ? ['subject' => $observation?->subject,
                    // A source-verified owner name is not a cross-document entity identity.
                    'reported_owner' => $observation?->integrity['governingOwner'] ?? null,
                    'owner' => null,
                    'value_basis' => $observation?->valueBasis, 'aggregation' => $observation?->aggregation,
                    'analytical_eligible' => ($observation?->integrity['analyticalEligible'] ?? false)
                        && $observation->rawValue === (string) $item->value
                        && trim($observation->measurement->unitText) === trim((string) $item->unit),
                    'verification' => ($observation?->integrity['sourceVerification'] ?? null) === 'verified' ? 'source_verified' : 'unverified'] : [];
                $key = $kind.':'.($kind === 'kpis' ? $item->identityKey($normalized) : $normalized);
                $result[$key][] = ['category' => $kind, 'label' => $item->$label, 'value' => $value, 'context' => $context,
                    'source' => ['document_id' => $document->id, 'document_name' => $document->name,
                        'intelligence_revision_id' => $document->published_intelligence_revision_id,
                        'insight_id' => $item->id, 'page_number' => $observation?->page,
                        'location_kind' => $observation?->page !== null ? 'page' : 'unknown',
                        'evidence' => $observation?->sourceContext ?: ($item->evidence ?? $item->context)]];
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
        $views = [app(DocumentIntelligenceService::class)->publishedView($base),
            app(DocumentIntelligenceService::class)->publishedView($new)];
        foreach ($views as $document) {
            abort_unless(in_array($document->status, ['Ready', 'Needs Review'], true), 422, 'Both documents must finish processing first.');
            $stages = app(DocumentIntelligenceService::class)->getProcessingStatus($document);
            foreach (['entities', 'risks', 'deadlines'] as $stage) {
                if (($stages[$stage] ?? null) === 'skipped') {
                    // A skipped attempt can reuse a prior completed result;
                    // an older processing row is never proof of active work.
                    $stages[$stage] = $document->processingJobs()->where('stage', $stage)
                        ->where('status', 'completed')->latest('created_at')->orderByDesc('id')->value('status');
                }
                abort_unless(in_array($stages[$stage] ?? null, ['completed', 'skipped'], true), 422, 'Structured extraction must complete successfully for both documents before comparison.');
            }
        }
        $before = $this->snapshot($views[0]);
        $after = $this->snapshot($views[1]);
        $metadata = ['algorithm_version' => self::ALGORITHM, 'method' => self::ALGORITHM, 'base' => $before, 'compared' => $after,
            'source_revisions' => ['base' => $views[0]->published_intelligence_revision_id,
                'compared' => $views[1]->published_intelligence_revision_id],
            'source_provenance' => $views[0]->published_intelligence_revision_id !== null
                && $views[1]->published_intelligence_revision_id !== null ? 'revision_pinned' : 'legacy_unversioned',
            'limitations' => 'Compares extracted intelligence, not every clause. Missing extraction is not proof a term was removed. Confirm changes in both originals; no legal authority is inferred.'];
        if ($includeTerms) {
            abort_unless(AiPrompt::where('name', 'document_comparison')->where('active', true)->exists(), 503, 'AI comparison is temporarily unavailable. Please try again later.');
            $contextService = app(ComparisonContextService::class);
            $metadata['method'] = 'structured-m0-v2-and-terms-v2';
            $metadata['ai_context'] = [];
            foreach (['base' => $views[0], 'compared' => $views[1]] as $side => $document) {
                $excerpts = $contextService->excerpts($document);
                abort_if(! $excerpts, 422, 'Document text is required for a terms comparison.');
                $metadata['ai_context'][$side] = ['document_id' => $document->id, 'document_name' => $document->name,
                    'intelligence_revision_id' => $document->published_intelligence_revision_id,
                    'excerpts' => $excerpts];
            }
            $prompt = app(PromptManager::class)->resolve('document_comparison');
            $metadata['prompt_version'] = $prompt->version;
            $metadata['prompt_hash'] = hash('sha256', $prompt->template);
            abort_if((int) $metadata['prompt_version'] < 2, 503, 'AI comparison prompt upgrade is required.');
            $metadata['model'] = app(AnthropicClient::class)->modelFor('document_comparison');
            $metadata['limitations'] .= ' Optional AI terms analysis uses at most 12 independently keyword-selected excerpts per document, from a bounded candidate window. Counterpart coverage is not guaranteed; clauses or differences may be missed. A malformed or ungrounded item rejects the entire AI result.';
        }

        $comparison = DB::transaction(function () use ($user, $base, $new, $metadata) {
            Workspace::whereKey($user->current_workspace_id)->lockForUpdate()->firstOrFail();
            $comparison = DocumentComparison::firstOrCreate([
                'workspace_id' => $user->current_workspace_id, 'base_document_id' => $base->id,
                'compared_document_id' => $new->id, 'fingerprint' => hash('sha256', json_encode($metadata)),
            ], ['created_by' => $user->id, 'matter_id' => $base->matter_id === $new->matter_id ? $base->matter_id : null, 'metadata' => $metadata]);
            $comparison = DocumentComparison::whereKey($comparison->id)->lockForUpdate()->firstOrFail();
            if (($comparison->dispatch_token === null && $comparison->status === 'queued') || $comparison->status === 'failed') {
                abort_if($comparison->provider_started_at !== null, 409, 'AI request outcome requires operational review before another paid attempt.');
                app(EntitlementService::class)->reserveComparison($comparison, true);
                app(ComparisonRecovery::class)->dispatch($comparison);
            }

            return $comparison;
        }, 3);
        app(ComparisonRecovery::class)->reconcile($comparison->id);

        return $comparison->refresh();
    }

    public function changes(array $before, array $after): array
    {
        // Entity categories are collections, never identities. Keep distinct observations separate.
        $rekey = function (array $snapshot): array {
            $result = [];
            foreach ($snapshot as $key => $rows) {
                foreach ($rows as $row) {
                    $identity = $row['category'] === 'entities'
                        ? $key.':'.mb_strtolower(trim(preg_replace('/\\s+/u', ' ', (string) $row['value']['value']))) : $key;
                    $result[$identity][] = $row;
                }
            }

            return $result;
        };
        $before = $rekey($before);
        $after = $rekey($after);
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $old = $before[$key] ?? [];
            $new = $after[$key] ?? [];
            $reference = $new[0] ?? $old[0];
            $reason = 'exact_label_candidate';
            if (! $old || ! $new) {
                $outcome = ! $old ? 'only_b' : 'only_a';
            } elseif ($reference['category'] === 'kpis') {
                // Ambiguous multiple candidates must not be silently zipped or cross-multiplied.
                $unique = fn ($rows) => array_unique(array_map(fn ($r) => json_encode([$r['value'], $r['context'] ?? []]), $rows));
                if (count($unique($old)) !== 1 || count($unique($new)) !== 1) {
                    [$outcome, $reason] = ['insufficient_evidence', 'ambiguous_candidates'];
                } elseif ($old[0]['value'] === $new[0]['value'] && ($old[0]['context'] ?? []) === ($new[0]['context'] ?? [])
                    && empty($old[0]['source']['evidence']) && empty($new[0]['source']['evidence'])
                    && ! ($old[0]['context']['analytical_eligible'] ?? false)) {
                    // Identical unverified extraction has no observed difference. This is not
                    // a statement of analytical equivalence or compatibility.
                    continue;
                } else {
                    [$outcome, $reason] = app(CrossDocumentNumericDecision::class)->decide($old[0], $new[0]);
                }
            } else {
                $values = function ($rows) {
                    return array_map(function ($row) {
                        $value = $row['value'];
                        unset($value['description']);

                        return $value;
                    }, $rows);
                };
                if ($values($old) === $values($new)) {
                    continue;
                }
                // A title alone does not establish matching obligation/party or legal effect.
                [$outcome, $reason] = ['insufficient_evidence', 'unverified_context'];
            }
            if ($outcome === 'equivalent') {
                continue;
            }
            $description = match ($outcome) {
                'only_a' => 'Observed only in Document A extraction; removal is not established.',
                'only_b' => 'Observed only in Document B extraction; addition is not established.',
                'not_comparable' => 'Not comparable: '.$reason.'.',
                'insufficient_evidence' => 'Insufficient evidence for a substantive comparison: '.$reason.'.',
                default => 'Different compatible extracted values; selection order does not establish chronology.',
            };
            $changes[] = ['category' => $reference['category'], 'label' => $reference['label'],
                'outcome' => $outcome, 'reasons' => [$reason], 'identity_basis' => 'canonical_definition_or_exact_label',
                'method' => 'structured', 'description' => $description, 'before' => $old, 'after' => $new];
        }

        return $changes;
    }
}
