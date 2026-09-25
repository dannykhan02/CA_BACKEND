<?php

namespace App\Services\Kpis;

use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\KpiAlias;
use App\Models\KpiDefinition;
use App\Services\AnthropicClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class KpiIdentityResolver
{
    public function __construct(private KpiIdentityProfile $profiles, private AnthropicClient $client) {}

    public function forDocument(Document $document, array $kpis): array
    {
        // One optional comparison for the whole extraction, never one per KPI.
        $budget = config('kpi_identity.semantic_matching', false) ? 1 : 0;
        if ($budget && DocumentAiRun::where('document_id', $document->id)
            ->where('purpose', 'kpi_identity')->where('file_hash', $document->file_hash)->exists()) {
            $budget = 0;
        }

        foreach ($kpis as &$kpi) {
            $result = $document->workspace_id
                ? $this->resolve($document->workspace_id, $kpi, document: $document, allowAi: $budget > 0)
                : ['definition_id' => null, 'profile' => $this->profiles->make($kpi), 'ai_attempted' => false];
            $kpi['kpi_definition_id'] = $result['definition_id'];
            $kpi['period'] = $result['profile']['period'];
            if ($result['ai_attempted']) {
                $budget--;
            }
        }

        return $kpis;
    }

    /** External adjudication happens before the short identity-write transaction. */
    public function resolve(string $workspaceId, array $kpi, bool $allowCreate = true, bool $dryRun = false, ?Document $document = null, bool $allowAi = false): array
    {
        $profile = $this->profiles->make($kpi);
        $result = ['definition_id' => null, 'profile' => $profile, 'method' => 'unresolved', 'ai_attempted' => false];
        if (! $profile['valid']) {
            return $result;
        }
        $match = $this->lookup($workspaceId, $profile);
        $adjudicated = null;
        if (! $match['definition'] && ! $match['ambiguous'] && $allowAi && ! $dryRun
            && $document?->workspace_id === $workspaceId && config('kpi_identity.semantic_matching', false)) {
            $candidates = $this->candidates($workspaceId, $profile);
            if ($candidates) {
                try {
                    $attemptKey = 'kpi-identity-attempt:'.$workspaceId.':'.$document->id.':'.hash('sha256', (string) $document->file_hash);
                    // An atomic shared-cache reservation also bounds concurrent jobs
                    // and timeout retries, before a provider audit record exists.
                    if (Cache::add($attemptKey, true, now()->addDay())) {
                        $result['ai_attempted'] = true;
                        $decision = $this->client->adjudicateKpiIdentity($profile, $candidates, $document);
                        if (($decision['relationship'] ?? null) === 'same' && ($decision['confidence'] ?? 0) >= 0.98
                            && isset($candidates[$decision['candidate_id'] ?? ''])) {
                            $adjudicated = $decision['candidate_id'];
                        }
                    }
                } catch (\Throwable $e) {
                    // Matching is optional; keep the extracted observation on provider failure.
                    Log::notice('KPI adjudication unavailable', ['document_id' => $document->id, 'exception' => $e::class]);
                }
            }
        }

        $finish = function () use ($workspaceId, $profile, $allowCreate, $dryRun, $adjudicated, $result) {
            if (! $dryRun) {
                // Serializes definition/alias learning in a workspace without locking credit
                // or document rows, and rechecks after any concurrent extraction/AI call.
                DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['kpi-identity:'.$workspaceId]);
            }
            $match = $this->lookup($workspaceId, $profile);
            $definition = $match['definition'];
            $method = $match['method'];
            if ($match['ambiguous']) {
                return $result;
            }
            if (! $definition && $adjudicated) {
                $candidate = KpiDefinition::where('workspace_id', $workspaceId)->find($adjudicated);
                if ($candidate && $this->profiles->compatible($profile, $candidate->matching_metadata)) {
                    $definition = $candidate;
                    $method = 'semantic';
                }
            }
            if (! $definition && ! $allowCreate) {
                return $result;
            }
            if (! $definition) {
                if ($dryRun) {
                    return array_replace($result, ['method' => 'would_create']);
                }
                $definition = KpiDefinition::create([
                    'workspace_id' => $workspaceId, 'canonical_name' => $profile['label'],
                    'normalized_name' => $profile['normalized_label'], 'concept' => $profile['concept'],
                    'scope' => $profile['scope'], 'metric_type' => $profile['metric_type'], 'unit' => $profile['unit'],
                    'matching_metadata' => $profile, 'identity_key' => $profile['identity_key'],
                ]);
                $method = 'created';
            }
            if (! $dryRun) {
                KpiAlias::firstOrCreate([
                    'workspace_id' => $workspaceId, 'normalized_label' => $profile['normalized_label'],
                    'context_key' => $profile['context_key'],
                ], [
                    'kpi_definition_id' => $definition->id, 'label' => $profile['label'],
                    'matching_metadata' => $profile, 'method' => $method,
                ]);
            }

            return array_replace($result, ['definition_id' => $definition->id, 'method' => $method]);
        };

        return $dryRun ? $finish() : DB::transaction($finish);
    }

    private function lookup(string $workspaceId, array $profile): array
    {
        $empty = ['definition' => null, 'method' => 'unresolved', 'ambiguous' => false];
        $alias = KpiAlias::with('definition')->where('workspace_id', $workspaceId)
            ->where('normalized_label', $profile['normalized_label'])->where('context_key', $profile['context_key'])->first();
        if ($alias) {
            if ($alias->definition && $this->profiles->compatible($profile, $alias->definition->matching_metadata)) {
                return ['definition' => $alias->definition, 'method' => 'alias', 'ambiguous' => false];
            }

            return array_replace($empty, ['ambiguous' => true]);
        }
        $exact = KpiDefinition::where('workspace_id', $workspaceId)->where('normalized_name', $profile['normalized_label'])
            ->get()->filter(fn ($d) => $this->profiles->compatible($profile, $d->matching_metadata));
        if ($exact->count() > 1) {
            return array_replace($empty, ['ambiguous' => true]);
        }
        if ($exact->count() === 1) {
            return ['definition' => $exact->first(), 'method' => 'normalized', 'ambiguous' => false];
        }
        $structured = KpiDefinition::where('workspace_id', $workspaceId)->where('identity_key', $profile['identity_key'])->first();
        if ($structured && $this->profiles->relationship($profile, $structured->matching_metadata) === 'same') {
            return ['definition' => $structured, 'method' => 'structured', 'ambiguous' => false];
        }

        return $empty;
    }

    /** Lexical overlap retrieves candidates ONLY; it never establishes identity. */
    private function candidates(string $workspaceId, array $profile): array
    {
        $words = array_values(array_filter(array_unique(explode(' ', $profile['base_name'])),
            fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, ['the', 'for', 'and', 'total', 'average', 'rate', 'percent', 'count', 'amount'], true)));
        if (! $words) {
            return [];
        }
        $definitions = KpiDefinition::where('workspace_id', $workspaceId)->where(function ($query) use ($words) {
            foreach (array_slice($words, 0, 8) as $word) {
                $query->orWhere('normalized_name', 'like', '%'.$word.'%');
            }
        })->orderBy('id')->limit(26)->get();
        if ($definitions->count() > 25) {
            return [];
        }
        $candidates = [];
        foreach ($definitions as $definition) {
            $other = $definition->matching_metadata;
            if (! $this->profiles->compatible($profile, $other)) {
                continue;
            }
            if (count(array_intersect($words, explode(' ', $other['base_name']))) < 2) {
                continue;
            }
            $candidates[$definition->id] = $other;
        }

        // Abstain rather than ask a model to select from an incomplete or giant catalog.
        return count($candidates) <= 3 ? $candidates : [];
    }
}
