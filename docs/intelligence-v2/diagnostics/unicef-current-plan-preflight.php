<?php

// Zero-provider, read-only source planner probe. Never reads the production DB.
$root = dirname(__DIR__, 3);
$source = file_get_contents($root.'/unicef-full-extracted.txt');
$raw = file_get_contents($root.'/unicef-source-spans.json');
if ($source === false || $raw === false
    || strlen($source) !== 58536
    || hash('sha256', $source) !== 'c919b4bb36fb3467906cc176a72d57c422260d6a17bbb4f4affc05c93415015c'
    || hash('sha256', $raw) !== 'df213788ebdc82a9fa98280cc47eed71c2f90862e027fe02b42fbd0882021b55') {
    fwrite(STDERR, "artifact gate failed\n");
    exit(1);
}
$rows = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
if (count($rows) !== 360) {
    fwrite(STDERR, "span count gate failed\n");
    exit(1);
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001']);
$stored = array_map(fn ($r) => [
    'ordinal' => (int) $r['ordinal'], 'key' => $r['span_key'], 'page' => (int) $r['page'],
    'start_offset' => (int) $r['start_offset'], 'end_offset' => (int) $r['end_offset'],
    'type' => $r['type'],
], $rows);
$version = '8acd7d011fac21b31b65c5dfa251423d';
$spans = new App\Services\AI\Incremental\EvidenceSpanSet($version, $source, $stored);
$capacity = app(App\Services\AI\Incremental\ExtractionCapacity::class);
$planner = app(App\Services\AI\Incremental\ChunkPlanner::class);
$estimatedTokens = $planner->estimate($source);
$density = $capacity->density($source, 'PDF');
$routing = $capacity->decide($estimatedTokens, $density);
$chunks = $planner->planFromSpans($spans, $source, $routing['partition_tokens'], true, 1/3);
$out = ['source_chars' => mb_strlen($source), 'source_bytes' => strlen($source),
    'source_hash' => hash('sha256', $source), 'span_file_hash' => hash('sha256', $raw),
    'span_count' => count($stored), 'estimated_tokens' => $estimatedTokens,
    'density' => $density, 'routing' => $routing, 'chunks' => []];
foreach ($chunks as $index => $chunk) {
    $chunkSpans = $spans->forRange($chunk['start_offset'], $chunk['end_offset']);
    $text = mb_substr($source, $chunk['start_offset'], $chunk['end_offset']-$chunk['start_offset']);
    $risk = app(App\Services\AI\Incremental\ProactiveChunkRisk::class)
        ->assess($text, $chunk['token_count'], $chunkSpans, 'PDF');
    $out['chunks'][] = ['index' => $index, ...$chunk,
        'source_bytes' => strlen($text), 'span_count' => $chunkSpans->count(),
        'first_span' => $chunkSpans->keys()[0] ?? null,
        'last_span' => array_slice($chunkSpans->keys(), -1)[0] ?? null,
        'payload_bytes' => strlen($chunkSpans->render()),
        'payload_hash' => hash('sha256', $chunkSpans->render()), 'planning_risk' => $risk];
}
file_put_contents(__DIR__.'/unicef-current-plan-preflight.json',
    json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode(['version' => $version, 'estimated_tokens' => $estimatedTokens,
    'density' => $density, 'routing' => $routing,
    'chunks' => array_map(fn ($x) => array_intersect_key($x,
        array_flip(['index','start_offset','end_offset','start_page','end_page','span_count','first_span','last_span','source_bytes','payload_bytes','planning_risk'])), $out['chunks'])],
    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n";
