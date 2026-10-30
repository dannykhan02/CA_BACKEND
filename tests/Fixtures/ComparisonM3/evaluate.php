<?php
/** Tier A. Replay frozen labels without writing earlier artifacts. */
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
require __DIR__.'/../ComparisonIdentity/run.php';
$service = app(App\Services\DocumentComparisonService::class);
$counts = ['cases' => 0, 'correct' => 0, 'false_substantive' => 0, 'missed_substantive' => 0];
$rows = [];
foreach (json_decode(file_get_contents(__DIR__.'/../ComparisonEvaluation/cases.json'), true, flags: JSON_THROW_ON_ERROR) as $case) {
    if (($case['layer'] ?? '') === 'integration') continue;
    $project = function ($records) {
        $out = [];
        foreach ($records as $row) {
            $key = $row['category'].':'.($row['category'] === 'kpis' ? 'label:' : '').mb_strtolower(trim(preg_replace('/\s+/u', ' ', $row['label'])));
            $out[$key][] = $row;
        }
        return $out;
    };
    $output = $service->changes($project($case['inputs']['a']), $project($case['inputs']['b']));
    $outcomes = array_unique(array_column($output, 'outcome'));
    $actual = !$outcomes ? 'equivalent' : (count($outcomes) === 1 ? reset($outcomes) : 'mixed');
    $expected = $case['expected']['outcome'];
    $positive = in_array($expected, ['supported_change','period_comparison','potential_conflict']);
    $substantive = count(array_filter($output, fn ($r) => in_array($r['outcome'], ['supported_change','period_comparison','potential_conflict'])));
    $counts['cases']++; $counts['correct'] += (int) ($actual === $expected);
    $counts['false_substantive'] += $positive ? 0 : $substantive;
    $counts['missed_substantive'] += (int) ($positive && !$substantive);
    $rows[] = ['id' => $case['id'], 'expected' => $expected, 'actual' => $actual, 'output' => $output];
}
$period = ['cases' => 0, 'correct' => 0, 'false_substantive' => 0, 'abstentions' => 0];
foreach (Tests\Unit\ComparisonM2B1PeriodTest::scenarios() as [$a,$b,$expected]) {
    $decision = app(App\Services\Intelligence\CrossDocumentNumericDecision::class)->decide(Tests\Unit\ComparisonM2B1PeriodTest::fact($a), Tests\Unit\ComparisonM2B1PeriodTest::fact($b,true));
    $period['cases']++; $period['correct'] += (int) ($decision[0] === $expected);
    $period['false_substantive'] += (int) (in_array($decision[0], ['supported_change','period_comparison']) && !in_array($expected,['supported_change','period_comparison']));
    $period['abstentions'] += (int) in_array($decision[0], ['not_comparable','insufficient_evidence']);
}
$identity = evaluateComparisonIdentity();
$report = ['algorithm' => $service::ALGORITHM, 'fact_counts' => $counts, 'facts' => $rows, 'identity' => $identity, 'period_counts' => $period];
file_put_contents(__DIR__.'/'.($argv[1] ?? 'current').'.json', json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['facts'=>$counts,'identity'=>$identity['counts'],'period'=>$period],JSON_PRETTY_PRINT)."\n";
if ($counts['false_substantive'] || $identity['counts']['false_identity_matches'] || $period['false_substantive']) exit(1);
