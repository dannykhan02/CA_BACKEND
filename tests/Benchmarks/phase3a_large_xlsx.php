<?php

use App\Services\Extraction\SpreadsheetTextExtractor;
use App\Services\Intelligence\StructuredProvenanceProducer;
use Illuminate\Contracts\Console\Kernel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Table;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

// Deterministic, no database or provider calls. Run: php tests/Benchmarks/phase3a_large_xlsx.php
require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$started = microtime(true);
$book = new Spreadsheet;
$sheet = $book->getActiveSheet();
$sheet->setTitle('Long report');
$sheet->fromArray([['Metric', 'FY2025', 'FY2024', 'FY2023']]);
for ($row = 2; $row <= 6001; $row++) {
    $sheet->fromArray([['Row'.$row, $row, $row * 2, $row * 3]], null, 'A'.$row);
}
$sheet->addTable(new Table('A1:D6001', 'LongReportTable'));
$path = tempnam(sys_get_temp_dir(), 'phase3a_scale_');
try {
    (new Xlsx($book))->save($path);
    $extracted = app(SpreadsheetTextExtractor::class)->extractStructured($path);
    $quote = 'Row6001 | 6001 | 12002 | 18003';
    $start = mb_strpos($extracted->text, $quote);
    if ($start === false) {
        throw new RuntimeException('Benchmark ground truth row was lost.');
    }
    $evidence = (object) ['id' => 'benchmark',
        'data' => ['value' => '6001', 'period' => 'FY2025', 'label' => 'Row6001'],
        'sources' => [['quote' => $quote, 'start_offset' => $start,
            'end_offset' => $start + mb_strlen($quote)]]];
    [$anchors, $relations, $bindings] = app(StructuredProvenanceProducer::class)
        ->build($extracted, [$evidence]);
    $roles = array_column($bindings, 'role');
    foreach (['value', 'period', 'metric'] as $role) {
        if (! in_array($role, $roles, true)) {
            throw new RuntimeException('Benchmark lost supported '.$role.' proof.');
        }
    }
    echo json_encode(['rows' => 6001, 'source_nodes' => count($extracted->nodes),
        'proof_anchors' => count($anchors), 'proof_relations' => count($relations),
        'elapsed_seconds' => round(microtime(true) - $started, 3),
        'peak_bytes' => memory_get_peak_usage(true)], JSON_THROW_ON_ERROR), PHP_EOL;
} finally {
    unlink($path);
    $book->disconnectWorksheets();
}
