<?php

/** Tier 1 only. Shared by PHPUnit and the offline artifact writer. */
function evaluateComparisonIdentity(): array
{
    $engine = app(\App\Services\Comparisons\IdentityCandidates::class);
    $service = app(\App\Services\DocumentComparisonService::class);
    $cases = json_decode(file_get_contents(__DIR__.'/cases.json'), true, flags: JSON_THROW_ON_ERROR);
    $counts = ['cases' => 0, 'correct_classifications' => 0, 'correct_identity_states' => 0,
        'false_substantive_rows' => 0, 'false_identity_matches' => 0, 'missed_supported_changes' => 0, 'abstentions' => 0];
    $results = [];
    foreach ($cases as $case) {
        $project = function ($rows) {
            $out = [];
            foreach ($rows as $row) {
                $name = $row['category'] === 'kpis' ? ($row['context']['reported_owner'] ?? '') : ($row['value']['value'] ?? '');
                $identifier = app(\App\Services\Comparisons\SourceIdentityIdentifier::class)->forName($name, $row['source']['spans'] ?? []);
                if ($identifier !== null) { $row['context']['organization_identifier'] = $identifier; }
                $key = $row['category'].':'.($row['context']['concept_id'] ?? mb_strtolower($row['label']));
                $out[$key][] = $row;
            }
            return $out;
        };
        $before = $project($case['a']); $after = $project($case['b']);
        $initial = $engine->build($before, $after);
        $decisions = [];
        if ($case['action'] !== null) {
            foreach ($initial['candidates'] as $candidate) { $decisions[$candidate['key']] = ['state' => $case['action'], 'version' => 1, 'id' => 'synthetic-review']; }
        }
        if ($case['stale_revision'] ?? false) {
            foreach ($after as &$rows) { foreach ($rows as &$row) { $row['source']['intelligence_revision_id'] = 'new-revision'; } unset($row); } unset($rows);
        }
        $context = $engine->build($before, $after, $decisions);
        $output = $service->changes($before, $after, $context);
        $outcomes = array_unique(array_column($output, 'outcome'));
        $outcome = ! $outcomes ? 'equivalent' : (count($outcomes) === 1 ? reset($outcomes) : 'mixed');
        $state = $context['candidates'][0]['state'] ?? 'unknown';
        $substantive = count(array_filter($output, fn ($r) => in_array($r['outcome'], ['supported_change', 'potential_conflict', 'period_comparison'])));
        $positive = in_array($case['expected_outcome'], ['supported_change', 'potential_conflict', 'period_comparison']);
        $counts['cases']++;
        $counts['correct_classifications'] += (int) ($outcome === $case['expected_outcome']);
        $counts['correct_identity_states'] += (int) ($state === $case['expected_identity']);
        $counts['false_substantive_rows'] += $positive ? 0 : $substantive;
        $counts['false_identity_matches'] += (int) ($engine->usage($before, $after, $context) !== [] && ! in_array($case['expected_identity'], ['established', 'confirmed']));
        $counts['missed_supported_changes'] += (int) ($positive && ! $substantive);
        $counts['abstentions'] += (int) (count(array_filter($output, fn ($r) => in_array($r['outcome'], ['insufficient_evidence', 'not_comparable']))) > 0);
        $results[] = ['id' => $case['id'], 'scenario' => $case['scenario'], 'expected' => $case['expected_outcome'],
            'actual' => $outcome, 'expected_identity' => $case['expected_identity'], 'identity' => $state, 'output' => $output,
            'candidate_count' => count($context['candidates']), 'applied_identity' => $engine->usage($before, $after, $context)];
    }
    return ['tier' => 1, 'ruleset' => $engine::VERSION, 'fixture_sha256' => hash_file('sha256', __DIR__.'/cases.json'), 'counts' => $counts, 'results' => $results];
}
