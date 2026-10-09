<?php

// Diagnostic-only, one-shot sender. No application models, jobs, retries or persistence.
$label = $argv[1] ?? '';
if (! in_array($label, ['C1','C2','T1','T2'], true)) {
    fwrite(STDERR, "Expected C1, C2, T1 or T2\n"); exit(2);
}
$dir = __DIR__.'/unicef-4call';
$fixture = __DIR__.'/unicef-safe-attribution-control-request.json';
$control = file_get_contents($fixture);
if ($control === false || strlen($control) !== 34178
    || hash('sha256', $control) !== '143b3385379edaa0955625350eb69da3a59814ad23dffb0676bbf073ccfccd91') {
    fwrite(STDERR, "Control fixture hash/length gate failed\n"); exit(3);
}
$decoded = json_decode($control, true, 512, JSON_THROW_ON_ERROR);
foreach (['temperature','top_p','top_k','stop_sequences'] as $key) {
    if (array_key_exists($key, $decoded)) { fwrite(STDERR, "Control sampling key present\n"); exit(3); }
}
if ($decoded['model'] !== 'claude-haiku-4-5-20251001' || $decoded['max_tokens'] !== 16000) {
    fwrite(STDERR, "Model/output limit changed\n"); exit(3);
}
$conservativeFourCallUsd = 4 * (strlen($control) * 1.25 + 16000 * 5) / 1000000;
if ($conservativeFourCallUsd > 1.00) {
    fwrite(STDERR, "Conservative cost gate exceeded: $".$conservativeFourCallUsd."\n"); exit(3);
}
$message = json_decode($decoded['messages'][0]['content'], true, 512, JSON_THROW_ON_ERROR);
if (($message['max_records'] ?? null) !== 79 || isset($message['already_extracted'])) {
    fwrite(STDERR, "Record/continuation gate failed\n"); exit(3);
}
$request = str_starts_with($label, 'T') ? substr($control, 0, -1).',"temperature":0}' : $control;
$test = json_decode($request, true, 512, JSON_THROW_ON_ERROR);
if (str_starts_with($label, 'T')) {
    $without = $test; unset($without['temperature']);
    if (($test['temperature'] ?? null) !== 0 || $without !== $decoded) {
        fwrite(STDERR, "T0 request differs beyond temperature\n"); exit(3);
    }
} elseif ($request !== $control) { fwrite(STDERR, "Control bytes changed\n"); exit(3); }
if (! is_dir($dir)) mkdir($dir, 0770, true);
$base = $dir.'/'.$label;
if (file_exists($base.'.request.json') || file_exists($base.'.response.json') || file_exists($base.'.metadata.json')) {
    fwrite(STDERR, "Call label already used; refusing retry\n"); exit(4);
}
file_put_contents($base.'.request.json', $request, LOCK_EX);

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$key = trim((string) config('services.anthropic.api_key'));
if ($key === '') { fwrite(STDERR, "Anthropic credential unavailable\n"); exit(5); }

$started = hrtime(true);
$response = null; $error = null;
try {
    $response = Illuminate\Support\Facades\Http::withHeaders([
        'x-api-key' => $key,
        'anthropic-version' => '2023-06-01',
        'content-type' => 'application/json',
    ])->timeout(180)->connectTimeout(10)->withBody($request, 'application/json')
        ->post('https://api.anthropic.com/v1/messages');
} catch (Throwable $e) {
    $error = get_class($e).': '.$e->getMessage();
}
$latencyMs = (int) ((hrtime(true)-$started)/1000000);
$raw = $response?->body();
if ($raw !== null) file_put_contents($base.'.response.json', $raw, LOCK_EX);
$parsed = null;
try { if ($raw !== null) $parsed = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
catch (Throwable $e) { $error ??= 'Response JSON decode: '.$e->getMessage(); }
$text = implode('', array_map(fn($b) => ($b['type'] ?? null) === 'text' ? (string) ($b['text'] ?? '') : '',
    is_array($parsed['content'] ?? null) ? $parsed['content'] : []));
$records = null;
try { $inner = json_decode($text, true, 512, JSON_THROW_ON_ERROR); $records = is_array($inner['records'] ?? null) ? count($inner['records']) : null; }
catch (Throwable) {}
$stop = $parsed['stop_reason'] ?? null;
$classification = $response === null || ! $response->successful() ? 'PROVIDER_ERROR'
    : ($stop === 'max_tokens' ? 'OUTPUT_TRUNCATION'
    : (in_array($stop, ['end_turn','stop_sequence'], true) ? 'NORMAL_COMPLETION' : 'OTHER'));
$usage = $parsed['usage'] ?? [];
$metadata = [
    'label'=>$label,'request_sha256'=>hash('sha256',$request),'request_bytes'=>strlen($request),
    'response_sha256'=>$raw === null ? null : hash('sha256',$raw),
    'http_status'=>$response?->status(),'model'=>$parsed['model'] ?? null,
    'stop_reason'=>$stop,'stop_classification'=>$classification,
    'input_tokens'=>$usage['input_tokens'] ?? null,
    'cache_creation_input_tokens'=>$usage['cache_creation_input_tokens'] ?? null,
    'cache_read_input_tokens'=>$usage['cache_read_input_tokens'] ?? null,
    'output_tokens'=>$usage['output_tokens'] ?? null,
    'latency_ms'=>$latencyMs,'provider_request_id'=>$response?->header('request-id') ?? $parsed['id'] ?? null,
    'returned_records'=>$records,'error'=>$error,
];
file_put_contents($base.'.metadata.json', json_encode($metadata, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n", LOCK_EX);
echo json_encode($metadata, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
if ($classification !== 'NORMAL_COMPLETION') exit(1);
