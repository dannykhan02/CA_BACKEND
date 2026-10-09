<?php
// Offline projection from the previously captured real-path control request. No HTTP or database.
require dirname(__DIR__, 4).'/vendor/autoload.php';
$dir = __DIR__;
$controlBytes = file_get_contents(dirname(__DIR__).'/unicef-safe-attribution-control-request.json');
if (hash('sha256', $controlBytes) !== '143b3385379edaa0955625350eb69da3a59814ad23dffb0676bbf073ccfccd91') {
    throw new RuntimeException('Frozen control changed');
}
$control = json_decode($controlBytes, true, 512, JSON_THROW_ON_ERROR);
$message = json_decode($control['messages'][0]['content'], true, 512, JSON_THROW_ON_ERROR);
if ($control['model'] !== 'claude-haiku-4-5-20251001' || $control['max_tokens'] !== 16000
    || $message['max_records'] !== 79 || array_intersect_key($control,
        array_flip(['temperature', 'top_p', 'top_k', 'stop_sequences'])) !== []) {
    throw new RuntimeException('Frozen model, limits or sampling changed');
}
$base = $control['system'][0]['text'];
$out = [];
foreach (['CONTROL' => [false, false], 'COLLECTOR_ONLY' => [true, false],
    'METADATA_ONLY' => [false, true], 'COLLECTOR_PLUS_METADATA' => [true, true]] as $name => [$collector, $metadata]) {
    $option = new App\Services\AI\Incremental\ExtractionExperiment($collector, $metadata);
    $request = $control;
    $request['system'][0]['text'] = $option->instructions($base);
    $bytes = $name === 'CONTROL' ? $controlBytes : json_encode($request, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
    $comparison = $decoded; $baseline = $control;
    unset($comparison['system'][0]['text'], $baseline['system'][0]['text']);
    if ($comparison !== $baseline) throw new RuntimeException("Isolation failed: $name");
    file_put_contents($dir.'/requests/'.$name.'.json', $bytes, LOCK_EX);
    $out[$name] = ['prompt_version' => $option->promptVersion('2'), 'sha256' => hash('sha256', $bytes),
        'bytes' => strlen($bytes), 'isolation' => 'system text only'];
}
file_put_contents($dir.'/request-hashes.json', json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n", LOCK_EX);
echo json_encode($out, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
