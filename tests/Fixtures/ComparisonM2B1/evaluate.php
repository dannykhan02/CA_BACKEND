<?php
// Tier 1 classifications only. Expected labels are separate from real extracted inputs.
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$counts = ['cases' => 0, 'correct_classifications' => 0, 'false_substantive_changes' => 0, 'missed_supported_differences' => 0,
    'unsafe_compatibility_conclusions' => 0, 'abstentions' => 0];
$results = [];
foreach (Tests\Unit\ComparisonM2B1PeriodTest::scenarios() as $name => [$a, $b, $expected]) {
    $left = Tests\Unit\ComparisonM2B1PeriodTest::fact($a);
    $right = Tests\Unit\ComparisonM2B1PeriodTest::fact($b, true);
    $decision = $app->make(App\Services\Intelligence\CrossDocumentNumericDecision::class)->decide($left, $right);
    $substantive = in_array($decision[0], ['supported_change', 'period_comparison'], true);
    $supported = in_array($expected, ['supported_change', 'period_comparison'], true);
    $counts['cases']++;
    $counts['correct_classifications'] += (int) ($decision[0] === $expected);
    $counts['false_substantive_changes'] += (int) ($substantive && ! $supported);
    $counts['missed_supported_differences'] += (int) ($supported && ! $substantive);
    $counts['unsafe_compatibility_conclusions'] += (int) (in_array($expected, ['not_comparable', 'insufficient_evidence'], true) && ! in_array($decision[0], ['not_comparable', 'insufficient_evidence'], true));
    $counts['abstentions'] += (int) in_array($decision[0], ['not_comparable', 'insufficient_evidence'], true);
    $results[] = ['name' => $name, 'expected' => $expected, 'decision' => $decision, 'a' => $left, 'b' => $right];
}
$report = ['tier' => 1, 'algorithm' => App\Services\DocumentComparisonService::ALGORITHM,
    'case_source_sha256' => hash_file('sha256', __DIR__.'/../../Unit/ComparisonM2B1PeriodTest.php'), 'counts' => $counts, 'results' => $results];
file_put_contents(__DIR__.'/synthetic-results.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($counts, JSON_PRETTY_PRINT)."\n";
if ($counts['correct_classifications'] !== $counts['cases']) exit(1);
