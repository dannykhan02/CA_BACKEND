<?php

namespace App\Services;

use App\Models\AiPrompt;
use App\Models\Document;
use App\Models\DocumentComparison;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AI\PromptManager;
use App\Services\Comparisons\ComparisonIdentityService;
use App\Services\Comparisons\ComparisonRecovery;
use App\Services\Comparisons\DeadlineDecision;
use App\Services\Comparisons\EntityDecision;
use App\Services\Comparisons\FinancialPeriodDecision;
use App\Services\Comparisons\IdentityCandidates;
use App\Services\Comparisons\ObligationDecision;
use App\Services\Comparisons\RiskDecision;
use App\Services\Intelligence\CrossDocumentNumericDecision;
use App\Services\Intelligence\PublishedIntelligenceProjection;
use Illuminate\Support\Facades\DB;

class DocumentComparisonService
{
    public const ALGORITHM = 'structured-m3-v1';

    public function __construct(private IntelligenceAccess $access) {}

    public function snapshot(Document $document): array
    {
        return app(PublishedIntelligenceProjection::class)->read($document)['facts'];
    }

    public function create(User $user, string $baseId, string $comparedId, bool $includeTerms = false): DocumentComparison
    {
        return DB::transaction(function () use ($user, $baseId, $comparedId, $includeTerms) {
            Workspace::whereKey($user->current_workspace_id)->lockForUpdate()->firstOrFail();
            // Pin current access and publication pointers under the same identity-mutation serialization.
            $base = $this->access->document($user, $baseId, true);
            $new = $this->access->document($user, $comparedId, true);
            abort_if($base->id === $new->id, 422, 'Choose two different documents.');
            $views = [app(DocumentIntelligenceService::class)->publishedView($base), app(DocumentIntelligenceService::class)->publishedView($new)];
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
            $identity = app(ComparisonIdentityService::class)->context($user, $base->id, $new->id, $before, $after);
            $metadata = ['algorithm_version' => self::ALGORITHM, 'method' => self::ALGORITHM, 'base' => $before, 'compared' => $after,
                'interpretation_rules' => FinancialPeriodDecision::RULE,
                'projection_version' => PublishedIntelligenceProjection::VERSION,
                'category_rules' => 'categories-m3-v1',
                'published_coverage' => ['base' => app(PublishedIntelligenceProjection::class)->coverage($before),
                    'compared' => app(PublishedIntelligenceProjection::class)->coverage($after)],
                'identity_context' => $identity,
                'identity_usage' => app(IdentityCandidates::class)->usage($before, $after, $identity),
                'source_revisions' => ['base' => $views[0]->published_intelligence_revision_id,
                    'compared' => $views[1]->published_intelligence_revision_id],
                'source_provenance' => $views[0]->published_intelligence_revision_id !== null
                    && $views[1]->published_intelligence_revision_id !== null ? 'revision_pinned' : 'legacy_unversioned',
                'limitations' => 'Compares extracted intelligence, not every clause. Missing extraction is not proof a term was removed. Confirm changes in both originals; no legal authority is inferred.'];
            if ($includeTerms) {
                abort_unless(AiPrompt::where('name', 'document_comparison')->where('active', true)->exists(), 503, 'AI comparison is temporarily unavailable. Please try again later.');
                $contextService = app(ComparisonContextService::class);
                $metadata['method'] = self::ALGORITHM.'-and-terms-v2';
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
        }, 3);
    }

    public function changes(array $before, array $after, ?array $identityContext = null): array
    {
        if ($identityContext !== null) {
            [$before, $after] = app(IdentityCandidates::class)->apply($before, $after, $identityContext);
        }
        // Entity categories are collections, never identities. Keep distinct observations separate.
        $rekey = function (array $snapshot): array {
            $result = [];
            foreach ($snapshot as $key => $rows) {
                foreach ($rows as $row) {
                    $identity = $row['category'] === 'entities' && ! isset($row['context']['relationship_candidate'])
                        ? $key.':'.($row['context']['entity_identity'] ?? mb_strtolower(trim(preg_replace('/\\s+/u', ' ', (string) $row['value']['value'])))) : $key;
                    $result[$identity][] = $row;
                }
            }

            return $result;
        };
        $before = $rekey($before);
        $after = $rekey($after);
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $key) {
            $interpretation = null;
            $old = $before[$key] ?? [];
            $new = $after[$key] ?? [];
            $reference = $new[0] ?? $old[0];
            $reason = 'exact_label_candidate';
            $m3 = isset($reference['context']['projection_version']);
            if (! $old || ! $new) {
                $outcome = ! $old ? 'only_b' : 'only_a';
            } elseif (isset($old[0]['context']['identity_blocked']) || isset($new[0]['context']['identity_blocked'])) {
                [$outcome, $reason] = ['insufficient_evidence', 'identity_unresolved'];
            } elseif ($m3 && (($old[0]['context']['ambiguous_source_context'] ?? false) || ($new[0]['context']['ambiguous_source_context'] ?? false))) {
                [$outcome, $reason] = ['insufficient_evidence', 'ambiguous_source_context'];
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
                    if (! $m3 || (empty($old[0]['source']['intelligence_revision_id']) && empty($new[0]['source']['intelligence_revision_id']))) {
                        continue;
                    }
                    [$outcome, $reason] = ['insufficient_evidence', 'ineligible_metric'];
                } else {
                    $decision = app(CrossDocumentNumericDecision::class)->decide($old[0], $new[0]);
                    [$outcome, $reason] = $decision;
                    $interpretation = $decision[2] ?? null;
                }
            } elseif ($m3 && $reference['category'] !== 'kpis') {
                if (count($old) !== 1 || count($new) !== 1) {
                    [$outcome, $reason] = ['insufficient_evidence', 'ambiguous_candidates'];
                } else {
                    [$outcome, $reason] = match ($reference['category']) {
                        'deadlines' => app(DeadlineDecision::class)->decide($old[0], $new[0]),
                        'obligations' => app(ObligationDecision::class)->decide($old[0], $new[0]),
                        'risks' => app(RiskDecision::class)->decide($old[0], $new[0]),
                        'entities' => app(EntityDecision::class)->decide($old[0], $new[0]),
                        default => ['insufficient_evidence', 'narrative_context_only'],
                    };
                    if ($reference['category'] === 'deadlines' && $reason === 'unverified_provision_context'
                        && isset($old[0]['context']['relationship_candidate'], $new[0]['context']['relationship_candidate'])) {
                        [$outcome, $reason] = ['potential_relationship', 'event_correspondence_requires_review'];
                    }
                }
            } elseif ($reference['category'] === 'deadlines') {
                if (count($old) !== 1 || count($new) !== 1) {
                    [$outcome, $reason] = ['insufficient_evidence', 'ambiguous_candidates'];
                } else {
                    [$outcome, $reason] = app(DeadlineDecision::class)->decide($old[0], $new[0]);
                }
            } elseif ($reference['category'] === 'entities' && count($old) === 1 && count($new) === 1
                && isset($old[0]['context']['entity_identity'])
                && $old[0]['context']['entity_identity'] === ($new[0]['context']['entity_identity'] ?? null)) {
                continue;
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
            if ($outcome === 'equivalent' && ! $m3) {
                continue;
            }
            $description = match ($outcome) {
                'equivalent' => 'Corresponding compatible observations have the same compared fields; no broader equivalence is established.',
                'potential_relationship' => 'Potential relationship requiring review: '.$reason.'. No substantive difference is established.',
                'only_a' => 'Observed only in Document A extraction; removal is not established.',
                'only_b' => 'Observed only in Document B extraction; addition is not established.',
                'not_comparable' => 'Not comparable: '.$reason.'.',
                'insufficient_evidence' => 'Insufficient evidence for a substantive comparison: '.$reason.'.',
                'period_comparison' => 'Reported performance across distinct periods; this does not establish a correction or contradiction. Difference is Document B minus Document A.',
                default => 'Different compatible extracted values; selection order does not establish chronology.',
            };
            if ($m3 && $reference['category'] === 'risks' && $outcome === 'supported_change') {
                $description = 'The extracted risk classification differs between corresponding observations; a real-world risk change is not established.';
            }
            if ($m3 && $reference['category'] === 'entities' && $outcome === 'supported_change') {
                $description = 'The extracted role labels differ for corresponding entity observations; a real-world role change is not established.';
            }
            $changes[] = ['category' => $reference['category'], 'label' => $reference['label'],
                'outcome' => $outcome, 'reasons' => [$reason], 'identity_basis' => $old[0]['context']['identity'] ?? $new[0]['context']['identity'] ?? ($m3 ? 'unresolved_correspondence' : 'canonical_definition_or_exact_label'),
                'method' => 'structured', 'description' => $description, 'before' => $old, 'after' => $new];
            if ($m3) {
                $index = array_key_last($changes);
                $changes[$index]['ruleset'] = self::ALGORITHM;
                $changes[$index]['compatibility'] = ['decision' => in_array($outcome, ['equivalent', 'supported_change', 'period_comparison'], true) ? 'compatible' : ($outcome === 'not_comparable' ? 'incompatible' : 'unresolved'), 'reason' => $reason];
                $changes[$index]['matching_basis'] = $old[0]['context']['identity'] ?? $old[0]['context']['relationship_candidate'] ?? $new[0]['context']['relationship_candidate'] ?? ['state' => 'unknown', 'basis' => $reason];
                $changes[$index]['compared_fields'] = match ($reference['category']) {
                    'obligations', 'deadlines' => ['timing'], 'risks' => ['extracted_severity', 'description'],
                    'entities' => ['identity', 'role'], 'kpis' => ['value', 'unit', 'period'], default => [],
                };
                $changes[$index]['limitations'] = array_values(array_unique(array_merge(
                    ...array_map(fn ($row) => $row['limitations'] ?? [], [...$old, ...$new]))));
                if ($reference['category'] === 'obligations') {
                    $changes[$index]['limitations'][] = 'Timing only; action, amount, beneficiary and conditions are not independently validated by this result. Workflow completion is excluded.';
                }
            }
            if ($interpretation !== null) {
                $changes[array_key_last($changes)]['interpretation'] = $interpretation;
            }
        }

        return $changes;
    }
}
