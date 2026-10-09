<?php

// Diagnostic only. Invoke once per fresh PHP CLI process; never loads production DB settings.
if ($argc !== 3 || ! in_array($argv[1], ['normal', 'frozen'], true)
    || ! preg_match('/^[ABC]$/', $argv[2])) {
    fwrite(STDERR, "usage: php unicef-request-probe.php normal|frozen A|B|C\n");
    exit(2);
}
$condition = $argv[1];
$label = $argv[2];
$root = dirname(__DIR__, 3);
$sourcePath = $root.'/unicef-leaf2.txt';
$source = file_get_contents($sourcePath);
if ($source === false || strlen($source) !== 37584
    || hash('sha256', $source) !== '5e9e9609210a288163288a1d64a9ffd5a153116d58f2ec201f6f76a0944ee023') {
    fwrite(STDERR, "source verification failed\n");
    exit(3);
}
foreach ([
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => ':memory:',
    'CACHE_STORE' => 'array', 'CACHE_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    'SESSION_DRIVER' => 'array', 'MAIL_MAILER' => 'array', 'LOG_CHANNEL' => 'stderr',
] as $key => $value) {
    putenv("$key=$value");
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if ($condition === 'frozen') {
    Illuminate\Support\Carbon::setTestNow('2026-10-09T00:00:00+03:00');
}
$schema = Illuminate\Support\Facades\Schema::connection('sqlite');
$schema->create('documents', function (Illuminate\Database\Schema\Blueprint $t) {
    $t->string('id')->primary();
    foreach (['workspace_id', 'uploaded_by', 'name', 'type', 'status', 'classification',
        'size_kb', 'year', 'pages', 'file_hash', 'extracted_text', 'ai_pipeline',
        'deleted_at', 'created_at', 'updated_at'] as $column) $t->text($column)->nullable();
});
$schema->create('document_chunks', function (Illuminate\Database\Schema\Blueprint $t) {
    $t->string('id')->primary();
    foreach (['workspace_id', 'document_id', 'pipeline_key', 'pipeline_version',
        'prompt_version', 'stage', 'status', 'start_offset', 'end_offset', 'start_page',
        'end_page', 'input_hash', 'attempts', 'depth', 'parent_id', 'token_count',
        'cost_accounting', 'reserved_cost', 'dispatched_at', 'created_at', 'updated_at'] as $column) $t->text($column)->nullable();
});
$schema->create('document_source_spans', function (Illuminate\Database\Schema\Blueprint $t) {
    $t->string('id')->primary();
    foreach (['workspace_id', 'document_id', 'extraction_version', 'span_key',
        'ordinal', 'page', 'start_offset', 'end_offset', 'type', 'created_at'] as $column) $t->text($column)->nullable();
});
$schema->create('document_ai_runs', function (Illuminate\Database\Schema\Blueprint $t) {
    $t->string('id')->primary();
    foreach ((new App\Models\DocumentAiRun)->getFillable() as $column) $t->text($column)->nullable();
});
foreach (['document_evidence', 'processing_jobs', 'jobs', 'billing_operations',
    'operation_quotes', 'workspace_credits', 'audit_logs'] as $trackedTable) {
    $schema->create($trackedTable, function (Illuminate\Database\Schema\Blueprint $t) {
        $t->string('id')->primary();
    });
}
config([
    'document_intelligence.provider_gate.driver' => 'memory',
    'services.anthropic.extraction_model' => 'claude-haiku-4-5-20251001',
    'ai_credits.enabled' => false,
]);
Illuminate\Support\Facades\Bus::fake();
Illuminate\Support\Facades\Queue::fake();
Illuminate\Support\Facades\Notification::fake();
Illuminate\Support\Facades\Mail::fake();
Illuminate\Support\Facades\Event::fake();
Illuminate\Support\Facades\Http::preventStrayRequests();

$db = Illuminate\Support\Facades\DB::connection();
$db->statement('PRAGMA foreign_keys = OFF');
$documentId = '00000000-0000-4000-8000-000000000101';
$chunkId = '00000000-0000-4000-8000-000000000102';
$workspaceId = '00000000-0000-4000-8000-000000000103';
$userId = '00000000-0000-4000-8000-000000000104';
$document = App\Models\Document::query()->forceCreate([
    'id' => $documentId, 'workspace_id' => $workspaceId, 'uploaded_by' => $userId,
    'name' => 'UNICEF report.pdf', 'type' => 'PDF', 'status' => 'Processing',
    'classification' => 'Internal', 'size_kb' => 37, 'year' => 2025,
    'pages' => 13, 'file_hash' => hash('sha256', $source), 'extracted_text' => $source,
]);
$grounding = app(App\Services\AI\Incremental\EvidenceGrounding::class);
$version = $grounding->version($document);
$document->forceFill(['ai_pipeline' => ['grounding' => App\Services\AI\Incremental\EvidenceGrounding::SPANS,
    'extraction_version' => $version, 'key' => 'diagnostic']])->save();
$spans = app(App\Services\AI\Incremental\SourceSpanBuilder::class)->build($source, 10);
foreach ($spans as $span) {
    Illuminate\Support\Facades\DB::table('document_source_spans')->insert([
        'id' => sprintf('00000000-0000-4000-8000-%012d', $span['ordinal']),
        'workspace_id' => $workspaceId, 'document_id' => $documentId,
        'extraction_version' => $version, 'span_key' => $span['key'],
        'ordinal' => $span['ordinal'], 'page' => $span['page'],
        'start_offset' => $span['start_offset'], 'end_offset' => $span['end_offset'],
        'type' => $span['type'], 'created_at' => '2026-10-09 00:00:00',
    ]);
}
$chunk = App\Models\DocumentChunk::query()->forceCreate([
    'id' => $chunkId, 'workspace_id' => $workspaceId, 'document_id' => $documentId,
    'pipeline_key' => 'diagnostic', 'pipeline_version' => '1', 'prompt_version' => '2',
    'stage' => 'extraction', 'status' => 'running', 'start_offset' => 0,
    'end_offset' => mb_strlen($source), 'start_page' => 10, 'end_page' => 22,
    'input_hash' => hash('sha256', $source), 'attempts' => 1, 'depth' => 1,
]);

$tableNames = array_map(fn ($row) => $row->name, $db->select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name"));
$counts = function () use ($db, $tableNames): array {
    $out = [];
    foreach ($tableNames as $table) {
        $out[$table] = $db->table($table)->count();
    }
    return $out;
};
$before = $counts();
$writes = [];
$db->listen(function ($query) use (&$writes) {
    if (preg_match('/^\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query->sql, $m)) {
        preg_match('/\b(?:into|update|from|table)\s+"?([a-z_]+)"?/i', $query->sql, $table);
        $writes[] = ['verb' => strtoupper($m[1]), 'table' => $table[1] ?? null,
            'sql_hash' => hash('sha256', $query->sql)];
    }
});
$body = null;
$httpRequests = 0;
$networkCalls = 0;
Illuminate\Support\Facades\Http::fake(function ($request) use (&$body, &$httpRequests) {
    $httpRequests++;
    if ($request->url() !== 'https://api.anthropic.com/v1/messages') {
        throw new RuntimeException('Unexpected HTTP URL; network prohibited');
    }
    $body = $request->body();
    return Illuminate\Support\Facades\Http::response([
        'id' => 'msg_diagnostic_fake', 'model' => 'claude-haiku-4-5-20251001',
        'stop_reason' => 'end_turn', 'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        'content' => [['type' => 'text', 'text' => '{"records":[]}']],
    ], 200, ['request-id' => 'diagnostic-fake']);
});
$error = null;
$db->beginTransaction();
try {
    app(App\Services\AnthropicClient::class)->extractChunk($document, $chunk, $source);
} catch (Throwable $e) {
    $error = get_class($e).': '.$e->getMessage();
} finally {
    $db->rollBack();
}
$after = $counts();
Illuminate\Support\Facades\Bus::assertNothingDispatched();
Illuminate\Support\Facades\Queue::assertNothingPushed();
Illuminate\Support\Facades\Notification::assertNothingSent();
Illuminate\Support\Facades\Mail::assertNothingOutgoing();
$sent = $body === null ? null : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
$keys = ['temperature', 'top_p', 'top_k', 'stop_sequences'];
$sampling = [];
foreach ($keys as $key) {
    $sampling[$key] = $sent === null ? 'NOT_CAPTURED' : (array_key_exists($key, $sent) ? 'PRESENT' : 'ABSENT');
}
$artifact = [
    'condition' => $condition, 'label' => $label, 'source_bytes' => strlen($source),
    'source_hash' => hash('sha256', $source), 'source_chars' => mb_strlen($source),
    'span_count' => count($spans), 'first_span' => $spans[0] ?? null,
    'last_span' => end($spans) ?: null, 'span_map_hash' => hash('sha256', json_encode($spans, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    'span_keys' => array_column($spans, 'key'), 'span_map' => $spans,
    'payload_hash' => hash('sha256', $grounding->payload($document, $chunk, $source)),
    'request_body_hash' => $body === null ? null : hash('sha256', $body),
    'request_body' => $body, 'sampling_keys' => $sampling,
    'model' => $sent['model'] ?? null, 'max_tokens' => $sent['max_tokens'] ?? null,
    'max_records' => isset($sent['messages'][0]['content'])
        ? (json_decode($sent['messages'][0]['content'], true)['max_records'] ?? null) : null,
    'http_fake_requests' => $httpRequests, 'anthropic_network_calls' => $networkCalls,
    'before_counts' => $before, 'after_counts' => $after,
    'persisted_row_count_delta' => array_sum($after) - array_sum($before),
    'row_counts_identical' => $before === $after, 'attempted_sql_writes' => $writes,
    'queue_dispatches' => 0, 'billing_events' => 0, 'other_side_effects' => [],
    'error' => $error,
];
$outDir = __DIR__.'/unicef-request-probe';
if (!is_dir($outDir)) mkdir($outDir, 0770, true);
file_put_contents($outDir.'/'.$condition.'-'.$label.'.json', json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
echo json_encode([
    'condition' => $condition, 'label' => $label,
    'body_hash' => $artifact['request_body_hash'], 'payload_hash' => $artifact['payload_hash'],
    'span_count' => $artifact['span_count'], 'http_fake_requests' => $httpRequests,
    'anthropic_network_calls' => $networkCalls, 'row_counts_identical' => $before === $after,
    'attempted_sql_writes' => count($writes), 'error' => $error,
], JSON_THROW_ON_ERROR)."\n";
if ($body === null || $httpRequests !== 1 || $networkCalls !== 0 || $before !== $after || $error !== null
    || count($writes) !== 1 || $writes[0]['table'] !== 'document_ai_runs'
    || in_array('PRESENT', $sampling, true)) exit(1);
