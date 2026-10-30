<?php
// Offline fact evaluation. Boundary cases are separately exercised by feature tests.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$phase = $argv[1] ?? 'post-m0';
if (! in_array($phase, ['baseline', 'baseline-current', 'post-m0'], true)) { throw new RuntimeException('Unknown evaluation phase.'); }
if (str_starts_with($phase, 'baseline')) { require __DIR__.'/baseline-engine.php'; }
$service = $app->make(str_starts_with($phase, 'baseline') ? App\Services\BaselineDocumentComparisonService::class : App\Services\DocumentComparisonService::class);
$fixtureFile = __DIR__.($phase === 'baseline' ? '/cases-v1.json' : '/cases.json');
$cases = json_decode(file_get_contents($fixtureFile), true, flags: JSON_THROW_ON_ERROR);
$results = []; $counts = ['cases' => 0, 'correct_classifications' => 0, 'false_substantive_changes' => 0, 'missed_substantive_changes' => 0, 'invalid_numeric_comparisons' => 0, 'unmatched_rows' => 0, 'duplicate_differences' => 0, 'unsupported_evidence_claims' => 0, 'incorrect_chronology_assumptions' => 0];
foreach ($cases as $case) {
    if (($case['layer'] ?? '') === 'integration') { continue; }
    $project = function ($rows) use ($phase) {
        $snapshot = [];
        foreach ($rows as $row) {
            $label = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $row['label'])));
            $key = $row['category'].':'.($row['category'] === 'kpis' ? 'label:' : '').$label;
            if (str_starts_with($phase, 'baseline')) {
                unset($row['context']);
                if ($row['category'] === 'kpis') { $row['value'] = array_intersect_key($row['value'], array_flip(['value', 'unit'])); }
            }
            $snapshot[$key][] = $row;
        }
        return $snapshot;
    };
    $output = $service->changes($project($case['inputs']['a']), $project($case['inputs']['b']));
    $outcomes = array_map(fn ($r) => $r['outcome'] ?? (!$r['before'] ? 'only_b' : (!$r['after'] ? 'only_a' : 'supported_change')), $output);
    $actual = !$outcomes ? 'equivalent' : (count(array_unique($outcomes)) === 1 ? $outcomes[0] : 'mixed');
    $expected = $case['expected']['outcome'];
    $counts['cases']++; $counts['correct_classifications'] += (int) ($actual === $expected);
    $substantive = count(array_filter($outcomes, fn ($x) => in_array($x, ['supported_change','potential_conflict','period_comparison'])));
    $isSubstantive = in_array($expected, ['supported_change','potential_conflict','period_comparison']);
    $counts['false_substantive_changes'] += $isSubstantive ? 0 : $substantive;
    $counts['missed_substantive_changes'] += (int) ($isSubstantive && !$substantive);
    $counts['invalid_numeric_comparisons'] += (int) ($case['inputs']['a'][0]['category'] === 'kpis' && $substantive && in_array($expected, ['not_comparable','insufficient_evidence']));
    $counts['unmatched_rows'] += count(array_filter($outcomes, fn ($x) => in_array($x,['only_a','only_b'])));
    $counts['duplicate_differences'] += max(0,count($output)-1) * (int) ($expected === 'equivalent');
    $counts['unsupported_evidence_claims'] += (int) ($substantive && ($case['expected']['abstention'] === 'missing_evidence'));
    $results[] = ['id'=>$case['id'],'expected'=>$expected,'actual'=>$actual,'correct'=>$actual === $expected,'output'=>$output];
}
$report=['phase'=>$phase,'fixture_sha256'=>hash_file('sha256',$fixtureFile),'counts'=>$counts,'results'=>$results,'measurement_limits'=>['Chronology requires UI review; zero is not an engine-level chronology guarantee.','Identity and compatibility labels describe the full target; abstention may intentionally remain an M0 miss.','Integration cases are excluded from numeric denominators and run as feature tests.']];
if ($phase === 'baseline' && file_exists(__DIR__.'/baseline.json')) {
    $frozen = json_decode(file_get_contents(__DIR__.'/baseline.json'), true, flags: JSON_THROW_ON_ERROR);
    if ($frozen !== $report) { throw new RuntimeException('Frozen baseline no longer reproduces; inspect fixtures and adapter.'); }
    echo "Frozen c53d254 baseline reproduced exactly.\n";
} else {
    file_put_contents(__DIR__.'/'.$phase.'.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
}
echo json_encode($counts,JSON_PRETTY_PRINT)."\n";
