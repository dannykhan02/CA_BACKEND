<?php

namespace App\Services\Comparisons;

use App\Enums\WorkspaceType;
use App\Models\ComparisonIdentityLink;
use App\Models\DocumentComparison;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DocumentComparisonService;
use App\Services\IntelligenceAccess;
use Illuminate\Support\Facades\DB;

class ComparisonIdentityService
{
    public function __construct(private IntelligenceAccess $access, private IdentityCandidates $candidates) {}

    public function context(User $user, string $a, string $b, array $before, array $after): array
    {
        $this->access->document($user, $a);
        $this->access->document($user, $b);
        $pair = [$a, $b];
        sort($pair);
        $keys = array_column($this->candidates->build($before, $after)['candidates'], 'key');
        $decisions = ComparisonIdentityLink::where('workspace_id', $user->current_workspace_id)
            ->where('document_a_id', $pair[0])->where('document_b_id', $pair[1])->orderBy('binding_key')
            ->whereIn('binding_key', $keys)
            ->get()->mapWithKeys(fn ($link) => [$link->binding_key => [
                'id' => $link->id, 'state' => $link->state, 'version' => $link->version,
                'reviewed_by' => $link->reviewed_by, 'reviewed_at' => $link->reviewed_at->toISOString(), 'reason' => $link->reason,
            ]])->all();

        return $this->candidates->build($before, $after, $decisions);
    }

    public function read(User $user, DocumentComparison $comparison): array
    {
        $this->authorize($user, $comparison);
        $saved = $comparison->metadata['identity_context'] ?? null;
        if ($saved === null) {
            return ['version' => null, 'candidates' => [], 'freshness' => 'unrecorded', 'can_review' => false];
        }
        $context = $this->context($user, $comparison->base_document_id, $comparison->compared_document_id,
            $comparison->metadata['base'], $comparison->metadata['compared']);
        $freshness = $this->digest($saved) === $this->digest($context) ? 'snapshot_current' : 'decisions_changed';
        $current = $this->currentContext($user, $comparison);
        $currentKeys = array_column($current['candidates'], 'key');
        foreach ($context['candidates'] as &$candidate) {
            $candidate['snapshot_state'] = collect($saved['candidates'])->firstWhere('key', $candidate['key'])['state'] ?? 'unknown';
            $candidate['stale'] = ! in_array($candidate['key'], $currentKeys, true);
            $candidate['reviewable'] = $candidate['reviewable'] && ! $candidate['stale'];
        }
        unset($candidate);
        $context['freshness'] = $freshness;
        $context['can_review'] = $user->currentWorkspace?->type === WorkspaceType::Personal
            || in_array($user->role, ['Analyst', 'Reviewer', 'Administrator'], true);

        return $context;
    }

    public function decide(User $user, DocumentComparison $comparison, string $key, string $state, int $version, string $reason): array
    {
        return DB::transaction(function () use ($user, $comparison, $key, $state, $version, $reason) {
            Workspace::whereKey($user->current_workspace_id)->lockForUpdate()->firstOrFail();
            $this->authorize($user, $comparison, true);
            $saved = $comparison->metadata['identity_context'] ?? null;
            abort_if($saved === null, 409, 'Create a structured comparison with identity review first.');
            $context = $this->context($user, $comparison->base_document_id, $comparison->compared_document_id,
                $comparison->metadata['base'], $comparison->metadata['compared']);
            $candidate = collect($context['candidates'])->firstWhere('key', $key);
            abort_if($candidate === null, 404);
            $link = ComparisonIdentityLink::where('workspace_id', $user->current_workspace_id)->where('binding_key', $key)->lockForUpdate()->first();
            abort_if(($link?->version ?? 0) !== $version, 409, 'This correspondence was reviewed by another request. Reload before deciding.');
            if ($state === 'revoked') {
                abort_unless($link?->state === 'confirmed', 409, 'Only a confirmed correspondence can be revoked.');
            } else {
                $current = collect($this->currentContext($user, $comparison)['candidates'])->firstWhere('key', $key);
                abort_unless($current !== null && $current['reviewable'], 409, 'Source revision, observation or evidence changed. Start a new comparison.');
                abort_if($state === 'confirmed' && $current['state'] === 'contradictory', 422, 'Conflicting source identifiers cannot be overridden by correspondence review.');
                abort_if($state === 'confirmed' && in_array($link?->state, ['rejected', 'revoked'], true), 409, 'Rejected or revoked correspondence cannot be silently reconfirmed.');
                if ($state === 'candidate') {
                    abort_unless(in_array($link?->state, ['rejected', 'revoked'], true), 409, 'Only rejected or revoked correspondence can be reopened for review.');
                }
            }
            $sides = [$candidate['a'], $candidate['b']];
            usort($sides, fn ($a, $b) => strcmp($a['source']['document_id'], $b['source']['document_id']));
            $now = now();
            $link ??= new ComparisonIdentityLink(['workspace_id' => $user->current_workspace_id, 'binding_key' => $key,
                'document_a_id' => $sides[0]['source']['document_id'], 'document_b_id' => $sides[1]['source']['document_id'],
                'revision_a_id' => $sides[0]['source']['intelligence_revision_id'], 'revision_b_id' => $sides[1]['source']['intelligence_revision_id'],
                'record_a_id' => $sides[0]['source']['source_id'], 'record_b_id' => $sides[1]['source']['source_id'],
                'kind' => $candidate['kind'], 'evidence' => ['basis' => $candidate['basis'], 'a' => $sides[0], 'b' => $sides[1]]]);
            $link->fill(['state' => $state, 'version' => $version + 1, 'reviewed_by' => $user->id, 'reviewed_at' => $now, 'reason' => $reason])->save();
            DB::table('comparison_identity_events')->insert(['link_id' => $link->id, 'version' => $link->version,
                'state' => $state, 'reviewed_by' => $user->id, 'reviewed_at' => $now, 'reason' => $reason]);

            return ['id' => $link->id, 'state' => $link->state, 'version' => $link->version];
        }, 3);
    }

    private function authorize(User $user, DocumentComparison $comparison, bool $write = false): void
    {
        abort_unless($comparison->workspace_id === $this->access->workspace($user, $write), 404);
        $this->access->document($user, $comparison->base_document_id, $write);
        $this->access->document($user, $comparison->compared_document_id, $write);
    }

    private function currentContext(User $user, DocumentComparison $comparison): array
    {
        $service = app(DocumentComparisonService::class);

        return $this->candidates->build(
            $service->snapshot($this->access->document($user, $comparison->base_document_id)),
            $service->snapshot($this->access->document($user, $comparison->compared_document_id)));
    }

    public function digest(array $context): string
    {
        return hash('sha256', json_encode($context, JSON_THROW_ON_ERROR));
    }
}
