<?php

namespace App\Services\Comparisons;

/** Deterministic, bounded suggestions. Identity, compatibility and interpretation stay separate. */
class IdentityCandidates
{
    public const VERSION = 'identity-m2a-v1';

    public const M3_VERSION = 'identity-m3-v1';

    public const MAX_FACTS = 2000;

    public const MAX_BUCKET = 8;

    public const MAX_CANDIDATES = 100;

    public function build(array $before, array $after, array $decisions = []): array
    {
        $version = self::VERSION;
        foreach ([...array_values($before), ...array_values($after)] as $rows) {
            foreach ($rows as $row) {
                if (isset($row['context']['projection_version'])) {
                    $version = self::M3_VERSION;
                    break 2;
                }
            }
        }
        $overflow = false;
        $buckets = [];
        foreach (['a' => $before, 'b' => $after] as $side => $snapshot) {
            $count = 0;
            foreach ($snapshot as $rows) {
                foreach ($rows as $row) {
                    if (++$count > self::MAX_FACTS) {
                        $overflow = true;
                        break 2;
                    }
                    foreach ($this->buckets($row) as [$kind, $key]) {
                        // Keep at most one overflow sentinel in each bucket.
                        if (count($buckets[$kind][$key][$side] ?? []) <= self::MAX_BUCKET) {
                            $buckets[$kind][$key][$side][] = $row;
                        }
                    }
                }
            }
        }
        $candidates = [];
        ksort($buckets);
        foreach ($buckets as $kind => $groups) {
            ksort($groups);
            foreach ($groups as $group) {
                $a = $group['a'] ?? [];
                $b = $group['b'] ?? [];
                if (count($a) > self::MAX_BUCKET || count($b) > self::MAX_BUCKET) {
                    $overflow = true;

                    continue;
                }
                foreach ($a as $left) {
                    foreach ($b as $right) {
                        $candidate = $this->candidate($kind, $left, $right, $version);
                        $key = $candidate['key'];
                        $candidate['ambiguous'] = count($a) > 1 || count($b) > 1;
                        if (isset($candidates[$key])) {
                            $candidate['ambiguous'] = $candidate['ambiguous'] || $candidates[$key]['ambiguous'];
                        }
                        $candidate['decision'] = $decisions[$key] ?? null;
                        if ($candidate['decision'] !== null) {
                            $candidate['state'] = $candidate['state'] === 'contradictory'
                                ? 'contradictory' : $candidate['decision']['state'];
                        }
                        $candidate['reviewable'] = $this->reviewable($candidate);
                        if ($candidate['state'] === 'established' && ! $candidate['reviewable']) {
                            $candidate['state'] = 'candidate';
                            $candidate['basis'] = 'unpublished_or_missing_identity_provenance';
                        }
                        $candidates[$key] = $candidate;
                        if (count($candidates) > self::MAX_CANDIDATES) {
                            $overflow = true;
                            break 3;
                        }
                    }
                }
            }
        }
        ksort($candidates);
        $candidates = array_slice($candidates, 0, self::MAX_CANDIDATES, true);
        // An incomplete universe must never establish identity automatically.
        if ($overflow) {
            foreach ($candidates as &$candidate) {
                $candidate['ambiguous'] = true;
            }
            unset($candidate);
        }

        return ['version' => $version, 'truncated' => $overflow, 'candidates' => array_values($candidates)];
    }

    public function apply(array $before, array $after, array $context): array
    {
        $accepted = [];
        $blocked = [];
        $degrees = [];
        foreach ($context['candidates'] ?? [] as $candidate) {
            if (! ($context['truncated'] ?? false) && in_array($candidate['state'], ['confirmed', 'established'], true)
                && ($candidate['state'] === 'confirmed' || ! $candidate['ambiguous'])) {
                $accepted[] = $candidate;
                foreach (['a', 'b'] as $side) {
                    $id = $candidate['kind'].':'.$this->occurrence($candidate[$side]);
                    $degrees[$id] = ($degrees[$id] ?? 0) + 1;
                }
            } elseif (in_array($candidate['state'], ['rejected', 'revoked', 'contradictory'], true)) {
                foreach (['a', 'b'] as $side) {
                    $blocked[$candidate['kind'].':'.$this->occurrence($candidate[$side])] = $candidate['state'];
                }
            }
        }
        $bindings = [];
        // Rejection concerns a pair, not every alternative for either occurrence.
        // A uniquely accepted pair can resolve other rejected/contradictory alternatives.
        foreach ($degrees as $id => $degree) {
            if ($degree === 1) {
                unset($blocked[$id]);
            }
        }
        foreach ($accepted as $candidate) {
            foreach (['a', 'b'] as $side) {
                $id = $candidate['kind'].':'.$this->occurrence($candidate[$side]);
                if ($degrees[$id] !== 1) {
                    $blocked[$id] = 'ambiguous';
                }
            }
            $a = $candidate['kind'].':'.$this->occurrence($candidate['a']);
            $b = $candidate['kind'].':'.$this->occurrence($candidate['b']);
            if (! isset($blocked[$a]) && ! isset($blocked[$b])) {
                $bindings[$a] = $bindings[$b] = $candidate;
            }
        }
        $suggestions = [];
        $suggestionDegrees = [];
        foreach ($context['candidates'] ?? [] as $candidate) {
            if (($context['truncated'] ?? false) || $candidate['state'] !== 'candidate') {
                continue;
            }
            foreach (['a', 'b'] as $side) {
                $id = $candidate['kind'].':'.$this->occurrence($candidate[$side]);
                $suggestions[$id] = $candidate;
                $suggestionDegrees[$id] = ($suggestionDegrees[$id] ?? 0) + 1;
            }
        }
        $project = function ($snapshot) use ($bindings, $blocked, $suggestions, $suggestionDegrees) {
            $out = [];
            foreach ($snapshot as $key => $rows) {
                foreach ($rows as $row) {
                    $rowKey = $key;
                    $kind = $this->kind($row);
                    $id = $kind.':'.$this->occurrence($row);
                    $candidate = $bindings[$id] ?? null;
                    if (isset($blocked[$id])) {
                        $row['context']['identity_blocked'] = $blocked[$id];
                        $row['context']['owner'] = null;
                    } elseif ($candidate !== null && ($this->bindingFact($row) === $this->bindingFact($candidate['a']) || $this->bindingFact($row) === $this->bindingFact($candidate['b']))) {
                        $token = 'pair:'.$candidate['key'];
                        $row['context']['identity'] = ['state' => $candidate['state'], 'basis' => $candidate['basis'],
                            'key' => $candidate['key'], 'decision' => $candidate['decision']];
                        if ($kind === 'reporting_owner') {
                            $row['context']['owner'] = $token;
                            $rowKey = $key.':owner:'.$token;
                            if (($candidate['a']['context']['subject_is_owner'] ?? false)
                                && ($candidate['b']['context']['subject_is_owner'] ?? false)) {
                                $row['context']['subject'] = $token;
                            }
                        } elseif ($kind === 'provision') {
                            $rowKey = $row['category'].':alignment:'.$candidate['key'];
                        } elseif ($kind === 'risk') {
                            $rowKey = 'risks:alignment:'.$candidate['key'];
                        } elseif ($kind === 'entity') {
                            $row['context']['entity_identity'] = $token;
                            $rowKey = 'entities:alignment:'.$candidate['key'];
                        }
                    }
                    if ($candidate === null && ! isset($blocked[$id]) && isset($row['context']['projection_version'], $suggestions[$id])) {
                        $suggestion = $suggestions[$id];
                        $sa = $suggestion['kind'].':'.$this->occurrence($suggestion['a']);
                        $sb = $suggestion['kind'].':'.$this->occurrence($suggestion['b']);
                        if (($suggestionDegrees[$sa] ?? 0) === 1 && ($suggestionDegrees[$sb] ?? 0) === 1
                            && ! isset($bindings[$sa]) && ! isset($bindings[$sb]) && ! isset($blocked[$sa]) && ! isset($blocked[$sb])) {
                            $row['context']['relationship_candidate'] = ['state' => 'candidate', 'key' => $suggestion['key'], 'basis' => $suggestion['basis']];
                            $rowKey = $row['category'].':candidate:'.$suggestion['key'];
                        }
                    }
                    $out[$rowKey][] = $row;
                }
            }
            ksort($out);

            return $out;
        };

        return [$project($before), $project($after)];
    }

    public function occurrence(array $row): string
    {
        return ($row['source']['document_id'] ?? '').':'.($row['source']['source_id'] ?? $row['source']['insight_id'] ?? '');
    }

    public function usage(array $before, array $after, array $context): array
    {
        $out = [];
        foreach ($this->apply($before, $after, $context) as $snapshot) {
            foreach ($snapshot as $rows) {
                foreach ($rows as $row) {
                    if (isset($row['context']['identity'])) {
                        $identity = $row['context']['identity'];
                        $out[$identity['key']] = $identity;
                    }
                }
            }
        }

        return array_values($out);
    }

    private function kind(array $row): string
    {
        return match ($row['category']) {
            'kpis' => 'reporting_owner', 'entities' => 'entity', 'deadlines', 'obligations' => 'provision', 'risks' => isset($row['context']['projection_version']) ? 'risk' : '', default => ''
        };
    }

    private function buckets(array $row): array
    {
        $kind = $this->kind($row);
        $c = $row['context'] ?? [];
        if ($kind === 'reporting_owner') {
            return ! empty($c['reported_owner']) && ! empty($c['concept_id']) ? [[$kind, $c['concept_id']]] : [];
        }
        if ($kind === 'entity') {
            if (! in_array($c['entity_type'] ?? $row['label'], ['organization', 'regulator'], true)) {
                return [];
            }
            $keys = [[$kind, 'name:'.$this->name($row['value']['value'] ?? '')]];
            if (isset($c['organization_identifier'])) {
                $keys[] = [$kind, 'id:'.json_encode($c['organization_identifier'])];
            }

            return $keys;
        }
        if ($kind === 'risk') {
            $keys = [[$kind, 'title:'.$this->name($row['label'])]];
            if (! empty($c['subject'])) {
                $keys[] = [$kind, 'subject:'.$this->name($c['subject']).':'.($c['risk_type'] ?? '')];
            }

            return $keys;
        }
        if ($kind === 'provision') {
            $keys = [[$kind, 'title:'.$this->name($row['label'])]];
            if (! empty($c['obligation_reference'])) {
                $keys[] = [$kind, 'reference:'.$this->name($c['obligation_reference'])];
            }

            return $keys;
        }

        return [];
    }

    private function candidate(string $kind, array $a, array $b, string $version): array
    {
        $sides = [$a, $b];
        usort($sides, fn ($x, $y) => strcmp($this->occurrence($x), $this->occurrence($y)));
        $key = hash('sha256', json_encode([$version, $kind, array_map($this->bindingFact(...), $sides)], JSON_THROW_ON_ERROR));
        $ia = $a['context']['organization_identifier'] ?? null;
        $ib = $b['context']['organization_identifier'] ?? null;
        $state = 'candidate';
        $basis = $kind === 'reporting_owner' ? 'shared_metric_concept' : ($kind === 'entity' ? 'normalized_name_candidate' : ($kind === 'risk' ? 'risk_subject_or_title_candidate' : 'title_or_reference_candidate'));
        if (($ia['conflict'] ?? false) || ($ib['conflict'] ?? false)) {
            $state = 'contradictory';
            $basis = 'conflicting_source_bound_identifiers';
        } elseif (in_array($kind, ['reporting_owner', 'entity'], true) && $ia !== null && $ib !== null
            && $ia['namespace'] === $ib['namespace'] && $ia['issuer'] === $ib['issuer'] && $ia['jurisdiction'] === $ib['jurisdiction']) {
            $state = $ia['value'] === $ib['value'] ? 'established' : 'contradictory';
            $basis = $state === 'established' ? 'matching_source_bound_identifier' : 'conflicting_source_bound_identifiers';
        }

        return ['key' => $key, 'kind' => $kind, 'state' => $state, 'basis' => $basis, 'a' => $a, 'b' => $b];
    }

    private function reviewable(array $candidate): bool
    {
        foreach (['a', 'b'] as $side) {
            if (empty($candidate[$side]['source']['intelligence_revision_id']) || empty($candidate[$side]['source']['source_id'])
                || empty($candidate[$side]['source']['evidence_ids']) || empty($candidate[$side]['source']['spans'])
                || trim((string) ($candidate[$side]['source']['evidence'] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    private function bindingFact(array $row): array
    {
        // A display-name edit does not change the source occurrence or its evidence.
        unset($row['source']['document_name']);

        return $row;
    }

    private function name(string $value): string
    {
        $value = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));

        return preg_replace('/\b(?:ltd\.?|limited)$/u', 'limited', $value);
    }
}
