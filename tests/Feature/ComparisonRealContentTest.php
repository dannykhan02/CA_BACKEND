<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentIntelligenceRevision;
use App\Models\User;
use App\Services\Comparisons\IdentityCandidates;
use App\Services\DocumentComparisonService;
use App\Services\DocumentTextExtractor;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\IncrementalMetricFieldVerifier;
use App\Services\Intelligence\PdfSourceCandidateCollector;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Tier 2: real bytes, deterministic supported extraction/proof, no fresh model execution. */
class ComparisonRealContentTest extends TestCase
{
    use RefreshDatabase;

    public function test_real_public_financial_content_reaches_snapshot_without_invented_extraction_rows(): void
    {
        config(['document_intelligence.pdf_table_structure' => true, 'document_intelligence.revision_v1' => false]);
        Http::preventStrayRequests();
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $snapshots = [];
        $documents = [];
        $extractions = [];
        foreach (['apple_fy25_q2_original.pdf', 'nasdaq_q3_2026_income_statement_page.pdf'] as $index => $file) {
            $path = base_path('tests/Fixtures/'.$file);
            $source = app(DocumentTextExtractor::class)->extractPdfStructured($path);
            $doc = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
                'name' => $file, 'type' => 'PDF', 'status' => 'Processing', 'classification' => 'Public',
                'size_kb' => (int) ceil(filesize($path) / 1024), 'year' => 2026, 'pages' => $index ? 1 : 3, 'file_hash' => hash_file('sha256', $path)]);
            $pipeline = ['route' => 'incremental', 'key' => 'incremental:real-m2a-'.$index];
            $doc->forceFill(['ai_pipeline' => $pipeline])->save();
            app(StructuredExtractionStore::class)->publish($doc, $source);
            $doc->refresh();
            $collected = app(PdfSourceCandidateCollector::class)->collect($doc);
            $set = app(TextRoleProvenanceProducer::class)->publish($doc);
            $verified = app(IncrementalMetricFieldVerifier::class)->verify($doc, $set);
            $revision = DocumentIntelligenceRevision::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                'generation' => 1, 'state' => 'published', 'extracted_text' => $source->text, 'extracted_text_hash' => hash('sha256', $source->text),
                'ai_pipeline' => $pipeline, 'provenance_set_id' => $set]);
            $doc->forceFill(['published_intelligence_revision_id' => $revision->id, 'intelligence_generation' => 1, 'status' => 'Ready'])->save();
            $snapshot = app(DocumentComparisonService::class)->snapshot($doc->fresh());
            $facts = array_merge(...array_values($snapshot));
            $eligible = array_values(array_filter($facts, fn ($f) => $f['context']['analytical_eligible']));
            $this->assertNotEmpty($eligible);
            foreach ($eligible as $fact) {
                $this->assertNotEmpty($fact['source']['evidence_ids']);
                $this->assertNotEmpty($fact['source']['spans']);
                $this->assertNull($fact['context']['owner']);
            }
            // Independent source review: these values are printed in the supplied PDFs.
            $target = $index ? '732' : '29,589';
            $this->assertNotEmpty(array_filter($eligible, fn ($f) => $f['label'] === 'Operating income' && $f['value']['value'] === $target));
            $snapshots[] = $snapshot;
            $extractions[] = ['file' => $file, 'file_sha256' => $doc->file_hash,
                'extracted_text_sha256' => hash('sha256', $source->text), 'parser_version' => $source->parserVersion,
                'facts' => array_map(fn ($fact) => ['source_record_id' => $fact['source']['source_id'],
                    'label' => $fact['label'], 'value' => $fact['value'], 'context' => $fact['context'],
                    'source_locations' => array_map(fn ($span) => ['start_offset' => $span['start_offset'], 'end_offset' => $span['end_offset'],
                        'page' => $span['page'] ?? null, 'quote_sha256' => hash('sha256', $span['quote'])], $fact['source']['spans'])], $facts)];
            $documents[] = ['file' => $file, 'sha256' => $doc->file_hash, 'parser_version' => $source->parserVersion,
                'collected' => $collected, 'field_verification' => $verified, 'snapshot_facts' => count($facts), 'eligible_facts' => count($eligible),
                'eligible_evidence_coverage' => count(array_filter($eligible, fn ($f) => ! empty($f['source']['spans']) && ! empty($f['source']['evidence_ids']))),
                'reporting_owners' => array_values(array_unique(array_column(array_column($eligible, 'context'), 'reported_owner')))];
        }
        $identity = app(IdentityCandidates::class)->build(...$snapshots);
        $changes = app(DocumentComparisonService::class)->changes(...[...$snapshots, $identity]);
        $substantive = array_filter($changes, fn ($c) => in_array($c['outcome'], ['supported_change', 'potential_conflict', 'period_comparison']));
        $this->assertEmpty($substantive, 'Apple and Nasdaq observations must not become ordinary changed values.');
        $this->assertEmpty(app(IdentityCandidates::class)->usage(...[...$snapshots, $identity]));
        Http::assertNothingSent();
        $report = ['tier' => 2, 'method' => 'real PDF bytes through deterministic extraction, stored evidence, field verification and published snapshot adapter; publication pointer constructed locally for this replay',
            'fresh_model_execution' => false, 'documents' => $documents, 'pair' => ['expected' => 'Different organizations and periods; no ordinary changed values',
                'candidate_count' => count($identity['candidates']), 'false_identity_matches' => 0, 'correctly_established_matches' => 0,
                'missed_supported_identity_matches' => 0, 'substantive_rows' => count($substantive), 'outcomes' => array_count_values(array_column($changes, 'outcome'))]];
        file_put_contents(base_path('tests/Fixtures/ComparisonIdentity/real-content-results.json'), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        file_put_contents(base_path('tests/Fixtures/ComparisonIdentity/real-content-extraction.json'), json_encode($extractions, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }
}
