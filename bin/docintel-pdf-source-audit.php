<?php

// Offline evaluation only. All database work is rolled back.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\User;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\PdfTableGeometryProbe;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\ChartCandidateBuilder;
use App\Services\Intelligence\IncrementalMetricFieldVerifier;
use App\Services\Intelligence\MetricCollector;
use App\Services\Intelligence\PdfSourceCandidateCollector;
use App\Services\Intelligence\SourceProvenanceReader;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\Intelligence\VerifiedPdfChartPersister;
use App\Services\WorkspaceService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

if (count($argv) < 2 || ! app()->environment('testing')
    || config('database.default') !== 'pgsql'
    || config('database.connections.pgsql.database') !== 'ca_document_intelligence_test'
    || ! in_array(config('database.connections.pgsql.host'), ['localhost', '127.0.0.1', '/tmp'], true)) {
    fwrite(STDERR, "Usage: APP_ENV=testing ... php bin/docintel-pdf-source-audit.php LOCAL_PDF ...\n");
    exit(2);
}

config()->set('document_intelligence.pdf_table_structure', true);
foreach (array_slice($argv, 1) as $path) {
    if (! is_file($path) || strtolower(pathinfo($path, PATHINFO_EXTENSION)) !== 'pdf') {
        fwrite(STDERR, "Expected local PDF: {$path}\n");
        exit(2);
    }
    DB::beginTransaction();
    try {
        $times = [];
        $time = static function (string $name, callable $action) use (&$times) {
            $start = hrtime(true);
            $result = $action();
            $times[$name] = round((hrtime(true) - $start) / 1e6, 1);

            return $result;
        };
        // An independent probe pass gives the pre-projection candidate count.
        // Its time is reported separately and is not part of pipeline time.
        $probe = $time('independent_probe_ms', static fn () => app(PdfTableGeometryProbe::class)->extract($path));
        $source = $time('pipeline_extraction_and_projection_ms', static fn () => app(DocumentTextExtractor::class)->extractPdfStructured($path));
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => basename($path), 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal',
            'size_kb' => (int) ceil(filesize($path) / 1024), 'year' => 2026,
            'pages' => 1, 'file_hash' => hash_file('sha256', $path)]);
        $document->forceFill(['ai_pipeline' => ['route' => 'incremental',
            'key' => 'incremental:offline-source-audit']])->save();
        app(StructuredExtractionStore::class)->publish($document, $source);
        $document->refresh();
        $collection = $time('collection_ms', static fn () => app(PdfSourceCandidateCollector::class)->collect($document));
        $set = $time('provenance_ms', static fn () => app(TextRoleProvenanceProducer::class)->publish($document));
        $facts = DocumentEvidence::where('document_id', $document->id)->get();
        $proofs = app(SourceProvenanceReader::class)->forEvidence($document,
            $facts->pluck('id')->all(), $set);
        $matched = count(array_filter($proofs,
            static fn ($proof) => $proof['capabilities']['structural'] ?? false));
        $verdicts = $time('verification_ms', static fn () => app(IncrementalMetricFieldVerifier::class)->verify($document, $set));
        $facts = DocumentEvidence::where('document_id', $document->id)->get();
        $eligible = array_values(array_filter(app(MetricCollector::class)
            ->collect($document)['observations'],
            static fn ($item) => $item->integrity['analyticalEligible']));
        $charts = app(ChartCandidateBuilder::class)->build($eligible)['candidates'];
        $persisted = $time('persistence_ms', static fn () => app(VerifiedPdfChartPersister::class)->persist($document, $set));
        $rows = [];
        foreach ($facts as $fact) {
            $label = $fact->data['label'] ?? '';
            $reason = $fact->data['grounding_rejection'] ?? 'verified';
            $rows[$label]['reasons'][$reason] = ($rows[$label]['reasons'][$reason] ?? 0) + 1;
            $rows[$label]['values'][] = $fact->data['value'] ?? null;
            $rows[$label]['unit'] = $fact->data['unit'] ?? null;
            $rows[$label]['entity'] = $fact->data['source_representation']['entity'] ?? null;
        }
        $reasons = [];
        foreach ($facts as $fact) {
            $reason = $fact->data['grounding_rejection'] ?? 'verified';
            $reasons[$reason] = ($reasons[$reason] ?? 0) + 1;
        }
        echo json_encode(['file' => realpath($path), 'sha256' => hash_file('sha256', $path),
            'bytes' => filesize($path),
            'pages' => count(array_filter($source->nodes,
                static fn ($node) => ($node['type'] ?? null) === 'page')),
            'geometry_candidates' => count($probe),
            'tables' => $source->capabilities['projected_tables'] ?? 0,
            'cells' => $collection['extracted'], 'candidates' => $collection['candidates'],
            'source_matched' => $matched, 'verdicts' => $verdicts,
            'eligible' => count($eligible), 'reasons' => $reasons,
            'chart_candidates' => array_column($charts, 'title'), 'persisted' => $persisted,
            'published_anchors' => DB::table('source_anchors')->where('set_id', $set)->count(),
            'published_edges' => DB::table('source_relationships')->where('set_id', $set)->count(),
            'rows' => $rows, 'timings' => $times,
            'php_peak_bytes' => memory_get_peak_usage(true)], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)."\n";
    } finally {
        DB::rollBack();
    }
}
