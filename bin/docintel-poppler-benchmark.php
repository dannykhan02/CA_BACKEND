<?php

use App\Services\DocumentTextExtractor;
use App\Services\Extraction\PdfTableGeometryProbe;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (count($argv) !== 2 || ! is_file($argv[1])) {
    fwrite(STDERR, "Usage: php bin/docintel-poppler-benchmark.php LOCAL_SINGLE_PAGE_PDF\n");
    exit(2);
}
$started = hrtime(true);
$probe = app(PdfTableGeometryProbe::class)->extract($argv[1]);
config()->set('document_intelligence.pdf_table_structure', true);
$source = app(DocumentTextExtractor::class)->extractPdfStructured($argv[1]);
$rows = array_values(array_filter($source->nodes,
    static fn ($node) => $node['type'] === 'row' && ! ($node['meta']['explicit_header'] ?? false)));
$cells = array_values(array_filter($source->nodes,
    static fn ($node) => $node['type'] === 'cell' && ($node['meta']['grid_column'] ?? 0) >= 2
        && ! ($node['meta']['header'] ?? false)));
echo json_encode(['file' => $argv[1], 'probe_tables' => count($probe),
    'projected_tables' => $source->capabilities['projected_tables'] ?? 0,
    'rows' => count($rows), 'numeric_cells' => count($cells),
    'seconds' => round((hrtime(true) - $started) / 1e9, 3),
    'peak_rss_kib' => getrusage()['ru_maxrss']], JSON_THROW_ON_ERROR)."\n";
