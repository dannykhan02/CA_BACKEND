<?php
/** Tier A, conditional category decisions using the independent regression labels. */
require __DIR__.'/../../../vendor/autoload.php';
$app = require __DIR__.'/../../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$engine = app(App\Services\Comparisons\IdentityCandidates::class);
$service = app(App\Services\DocumentComparisonService::class);
$results = [];
$counts = ['cases' => 0, 'correct' => 0, 'false_substantive' => 0, 'missed_supported' => 0, 'unreviewed_identity_applied' => 0];
$byCategory = [];
foreach (Tests\Unit\ComparisonM3CategoryTest::cases() as $name => [$category,$replace,$state,$expected]) {
    $a = Tests\Unit\ComparisonM3CategoryTest::fact($category);
    $b = Tests\Unit\ComparisonM3CategoryTest::fact($category,'b',$replace);
    $before = [$category.':title' => [$a]]; $after = [$category.':title' => [$b]];
    $initial = $engine->build($before,$after); $decisions = [];
    foreach ($initial['candidates'] as $candidate) $decisions[$candidate['key']] = ['state'=>$state,'version'=>1,'id'=>'authored-review'];
    $context = $engine->build($before,$after,$decisions);
    $rows = $service->changes($before,$after,$context);
    $actual = count($rows) === 1 ? $rows[0]['outcome'] : 'unexpected_count';
    $counts['cases']++; $counts['correct'] += (int) ($actual === $expected);
    $counts['false_substantive'] += (int) ($actual === 'supported_change' && $expected !== 'supported_change');
    $counts['missed_supported'] += (int) ($expected === 'supported_change' && $actual !== $expected);
    $counts['unreviewed_identity_applied'] += (int) ($state === 'candidate' && $engine->usage($before,$after,$context) !== []);
    $byCategory[$category][$actual] = ($byCategory[$category][$actual] ?? 0) + 1;
    $results[] = compact('name','category','state','expected','actual') + ['rows'=>$rows];
}
$report=['tier'=>'A','limits'=>'Authored inputs and conditional explicit review, not extraction accuracy or a completed-analysis product pass.',
    'counts'=>$counts,'by_category'=>$byCategory,'results'=>$results];
file_put_contents(__DIR__.'/category-results.json',json_encode($report,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo json_encode(['counts'=>$counts,'by_category'=>$byCategory],JSON_PRETTY_PRINT)."\n";
if ($counts['correct'] !== $counts['cases'] || $counts['false_substantive'] || $counts['unreviewed_identity_applied']) exit(1);
