<?php

// Read-only extraction snapshot for the local, authorized evaluation corpus.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if ($argc !== 3 || ! in_array($argv[1], ['PDF', 'DOCX', 'XLSX'], true) || ! is_file($argv[2])) {
    fwrite(STDERR, "Usage: php tools/evidence_corpus_extract.php PDF|DOCX|XLSX local-file\n");
    exit(2);
}
$type = $argv[1];
$path = $argv[2];
$structured = match ($type) {
    'PDF' => app(App\Services\DocumentTextExtractor::class)->extractPdfStructured($path),
    'DOCX' => app(App\Services\DocumentTextExtractor::class)->extractDocxStructured($path),
    'XLSX' => app(App\Services\Extraction\SpreadsheetTextExtractor::class)->extractStructured($path),
};
$textHash = hash('sha256', $structured->text);
$revision = substr(hash('sha256', $textHash.'|'.config('document_intelligence.span_segmenter_version')), 0, 32);
$pagesKnown = $type === 'PDF' && substr_count($structured->text, "\f") + 1 === count(array_filter(
    $structured->nodes, fn (array $node) => ($node['type'] ?? null) === 'page'));
$spans = app(App\Services\AI\Incremental\SourceSpanBuilder::class)
    ->build($structured->text, $pagesKnown ? 1 : null);
foreach ($spans as &$span) {
    $span['quote'] = mb_substr($structured->text, $span['start_offset'],
        $span['end_offset'] - $span['start_offset']);
}
unset($span);
echo json_encode(['format' => $type, 'parser_version' => $structured->parserVersion,
    'text' => $structured->text, 'text_sha256' => $textHash, 'source_span_revision' => $revision,
    'spans' => $spans, 'nodes' => $structured->nodes, 'capabilities' => $structured->capabilities],
    JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
