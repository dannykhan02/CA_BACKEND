<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\DocumentIntelligenceService;
use App\Services\Extraction\StructuredDocument;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\SourceProvenance;
use App\Services\Intelligence\SourceReferenceResolver;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DocumentSourceCellWindowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $text = "=== Sheet: Results ===\nMetric | FY2025 | Percent\nRevenue | 12.4 | 0.25\nDate | 45292 | 24.8\nTotal | ";
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Results.xlsx', 'type' => 'XLSX',
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2025, 'file_hash' => hash('sha256', 'test workbook')]);
        $nodes = [['id' => 'sheet:0', 'type' => 'worksheet', 'sheet' => 'Results', 'path' => 'sheet:0',
            'meta' => ['order' => 0, 'visibility' => 'visible', 'date_system' => 1900]],
            ['id' => 'sheet:0/table:ResultsTable', 'type' => 'table', 'parent' => 'sheet:0',
                'sheet' => 'Results', 'path' => 'sheet:0/table:ResultsTable',
                'meta' => ['range' => 'A1:C3', 'header_row' => true]]];
        $values = [
            'A1' => ['Metric', 'Metric', null, null, 'General'],
            'B1' => ['FY2025', 'FY2025', null, null, 'General'],
            'C1' => ['Percent', 'Percent', null, null, 'General'],
            'A2' => ['Revenue', 'Revenue', null, null, 'General'],
            'B2' => [12.4, '12.4', null, null, '0.0'],
            'C2' => [0.25, '25%', null, null, '0%'],
            'A3' => ['Date', 'Date', null, null, 'General'],
            'B3' => [45292, '2024-01-01', null, null, 'yyyy-mm-dd'],
            'C3' => ['=B2*2', null, '=B2*2', 24.8, '0.0'],
            'A4' => ['Total', 'Total', null, null, 'General'],
        ];
        foreach ($values as $at => [$raw, $display, $formula, $cache, $format]) {
            $projected = $formula === null ? (string) $raw : (string) $cache;
            $start = mb_strpos($text, $projected);
            $nodes[] = ['id' => 'sheet:0/cell:'.$at, 'type' => 'cell', 'parent' => 'sheet:0',
                'sheet' => 'Results', 'path' => 'sheet:0!'.$at,
                'start' => $start, 'end' => $start + mb_strlen($projected), 'text' => $projected,
                'meta' => ['coordinate' => $at, 'cell_type' => $formula === null ? 'n' : 'f',
                    'raw' => $raw, 'display' => $display, 'formula' => $formula,
                    'cached_result' => $cache, 'cache_present' => $formula !== null,
                    'number_format' => $format, 'rich_text' => false,
                    'merges' => $at === 'A4' ? ['A4:B4'] : [], 'sheet_visibility' => 'visible',
                    'row_visible' => $at !== 'B3', 'row_visible_after_filter' => true,
                    'column_visible' => $at !== 'C2']];
        }
        // The sheet header contains "Metric" only once; every projected
        // cell value above must be a literal slice of this exact text.
        app(StructuredExtractionStore::class)->publish($document,
            new StructuredDocument('XLSX', 'fixture.v1', $text, $nodes,
                ['nodes_truncated' => false, 'cells' => 'complete']));
        $sources = [];
        foreach (['value' => '12.4', 'formula' => '24.8', 'date' => '45292'] as $name => $quote) {
            $sourceId = 'kpi:'.Str::uuid();
            $start = mb_strpos($text, $quote);
            DocumentEvidence::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'pipeline_key' => 'legacy',
                'identity' => hash('sha256', $name), 'kind' => 'metric', 'source_id' => $sourceId,
                'data' => ['value' => $quote],
                'sources' => [['quote' => $quote, 'start_offset' => $start,
                    'end_offset' => $start + mb_strlen($quote), 'span_id' => $name]]]);
            $sources[$name] = $sourceId;
        }
        $rowQuote = 'Revenue | 12.4 | 0.25';
        $rowStart = mb_strpos($text, $rowQuote);
        $sources['row'] = 'kpi:'.Str::uuid();
        DocumentEvidence::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'pipeline_key' => 'legacy',
            'identity' => hash('sha256', 'row source'), 'kind' => 'metric',
            'source_id' => $sources['row'],
            'data' => ['value' => '12.4', 'label' => 'Revenue', 'period' => 'FY2025'],
            'sources' => [['quote' => $rowQuote, 'start_offset' => $rowStart,
                'end_offset' => $rowStart + mb_strlen($rowQuote), 'span_id' => 'row']]]);
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        $structureId = DB::table('structured_extractions')->where('document_id', $document->id)->value('id');
        $revision = DocumentIntelligenceRevision::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'generation' => 1, 'state' => 'published', 'file_hash' => $document->file_hash,
            'extracted_text' => $text, 'extracted_text_hash' => hash('sha256', $text),
            'pipeline_key' => 'legacy', 'ai_pipeline' => ['key' => 'legacy'],
            'parser_version' => 'fixture.v1', 'structure_policy' => StructuredDocument::POLICY,
            'structured_extraction_id' => $structureId, 'provenance_set_id' => $setId,
            'provenance_policy' => SourceProvenance::POLICY,
        ]);
        $document->forceFill(['published_intelligence_revision_id' => $revision->id,
            'intelligence_generation' => 1])->save();

        return [$user, $document, $revision, $sources];
    }

    private function url(Document $document, string $sourceId, string $revisionId): string
    {
        return "/api/documents/{$document->id}/sources/{$sourceId}/cell-window?expected_revision_id={$revisionId}";
    }

    public function test_exact_cell_window_keeps_literal_display_formula_cache_and_visibility_distinct(): void
    {
        [$user, $document, $revision, $sources] = $this->fixture();
        Sanctum::actingAs($user);
        $response = $this->getJson($this->url($document, $sources['value'], $revision->id))->assertOk();
        $response->assertJsonPath('data.intelligenceRevisionId', $revision->id)
            ->assertJsonPath('data.sheet.name', 'Results')
            ->assertJsonPath('data.focus.coordinate', 'B2')
            ->assertJsonPath('data.window.truncated.top', true)
            ->assertJsonPath('data.window.truncated.left', true)
            ->assertJsonPath('data.hiddenRows.0', 3)
            ->assertJsonPath('data.hiddenColumns.0', 3)
            ->assertJsonPath('data.mergedRanges.0', 'A4:B4')
            ->assertJsonPath('data.citation.resolution', 'EXACT_CELL_ANCHOR')
            ->assertJsonPath('data.citation.coversMultipleCells', false)
            ->assertJsonPath('data.citation.coveredCells', ['B2']);
        $cells = collect($response->json('data.window.rows'))->flatMap(fn ($row) => $row['cells'])->keyBy('coordinate');
        self::assertSame(12.4, $cells['B2']['rawValue']);
        self::assertSame('12.4', $cells['B2']['displayValue']);
        self::assertTrue($cells['B2']['rowVisible']);
        self::assertSame(0.25, $cells['C2']['rawValue']);
        self::assertSame('25%', $cells['C2']['displayValue']);
        self::assertSame(45292, $cells['B3']['rawValue']);
        self::assertSame('2024-01-01', $cells['B3']['displayValue']);
        self::assertFalse($cells['B3']['rowVisible']);
        self::assertSame('=B2*2', $cells['C3']['rawValue']);
        self::assertSame('=B2*2', $cells['C3']['formula']);
        self::assertSame(24.8, $cells['C3']['cachedResult']);
        self::assertNull($cells['C3']['displayValue']);
        self::assertTrue($cells['C3']['cachePresent']);
        self::assertFalse($cells['D2']['present']);
        self::assertLessThan(65536, strlen($response->getContent()));
        self::assertStringNotContainsString('file_path', $response->getContent());
    }

    public function test_formula_and_date_can_be_the_cited_cell_without_recalculation(): void
    {
        [$user, $document, $revision, $sources] = $this->fixture();
        Sanctum::actingAs($user);
        $this->getJson($this->url($document, $sources['formula'], $revision->id))
            ->assertOk()->assertJsonPath('data.focus.coordinate', 'C3');
        $this->getJson($this->url($document, $sources['date'], $revision->id))
            ->assertOk()->assertJsonPath('data.focus.coordinate', 'B3');
    }

    public function test_a_row_citation_uses_its_verified_value_cell_not_a_related_header(): void
    {
        [$user, $document, $revision, $sources] = $this->fixture();
        Sanctum::actingAs($user);
        $resolved = app(SourceReferenceResolver::class)->resolve(
            app(DocumentIntelligenceService::class)->publishedView($document), $sources['row']);
        self::assertNotEmpty($resolved['roles']['value'] ?? null);
        $this->getJson($this->url($document, $sources['row'], $revision->id))
            ->assertOk()->assertJsonPath('data.focus.coordinate', 'B2')
            ->assertJsonPath('data.citation.matchState', 'STRUCTURALLY_RESOLVED')
            ->assertJsonPath('data.citation.resolution', 'EXACT_CELL_ANCHOR')
            ->assertJsonPath('data.citation.coveredCells', ['B2']);
    }

    public function test_read_stays_on_the_published_extraction_even_if_another_becomes_active(): void
    {
        [$user, $document, $revision, $sources] = $this->fixture();
        $pinned = DB::table('structured_extractions')->where('id', $revision->structured_extraction_id)->firstOrFail();
        DB::table('structured_extractions')->where('id', $pinned->id)->update(['active' => false]);
        $otherNodes = json_decode($pinned->nodes, true);
        foreach ($otherNodes as &$node) {
            if (($node['id'] ?? null) === 'sheet:0/cell:B2') {
                $node['meta']['raw'] = 99;
            }
        }
        unset($node);
        DB::table('structured_extractions')->insert([
            'id' => (string) Str::uuid(), 'workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'source_hash' => $document->file_hash,
            'text_hash' => $pinned->text_hash, 'parser_version' => 'fixture.v2',
            'policy_version' => StructuredDocument::POLICY, 'format' => 'XLSX',
            'nodes' => json_encode($otherNodes), 'capabilities' => $pinned->capabilities,
            'active' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        Sanctum::actingAs($user);
        $url = $this->url($document, $sources['value'], $revision->id);
        $response = $this->getJson($url)->assertOk();
        $cells = collect($response->json('data.window.rows'))->flatMap(fn ($row) => $row['cells'])->keyBy('coordinate');
        self::assertSame(12.4, $cells['B2']['rawValue']);
        DB::table('structured_extractions')->where('id', $pinned->id)->delete();
        $this->getJson($url)->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }

    public function test_missing_invalid_and_old_revision_ids_are_refused(): void
    {
        [$user, $document, $revision, $sources] = $this->fixture();
        Sanctum::actingAs($user);
        $base = "/api/documents/{$document->id}/sources/{$sources['value']}/cell-window";
        $this->getJson($base)->assertStatus(422)->assertJsonPath('error.code', 'INVALID_SOURCE_REVISION');
        $this->getJson($base.'?expected_revision_id=nope')
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_SOURCE_REVISION');
        $this->getJson($this->url($document, $sources['value'], (string) Str::uuid()))
            ->assertStatus(409)->assertJsonPath('error.code', 'STALE_SOURCE_REFERENCE');
        $document->forceFill(['file_hash' => hash('sha256', 'replaced workbook')])->save();
        $this->getJson($this->url($document, $sources['value'], $revision->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }

    public function test_unsupported_document_type_and_unknown_reference_are_refused(): void
    {
        [$user, $document, $revision, $sources] = $this->fixture();
        Sanctum::actingAs($user);
        $this->getJson($this->url($document, 'kpi:'.Str::uuid(), $revision->id))->assertNotFound();
        $document->forceFill(['type' => 'PDF'])->save();
        $this->getJson($this->url($document, $sources['value'], $revision->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }

    public function test_ambiguous_and_invalid_cell_coordinates_are_refused(): void
    {
        [$user, $document, $revision, $sources] = $this->fixture();
        Sanctum::actingAs($user);
        $evidence = DocumentEvidence::where('source_id', $sources['value'])->firstOrFail();
        $evidence->update(['sources' => [
            ['quote' => '12.4', 'start_offset' => 61, 'end_offset' => 65],
            ['quote' => '12.4', 'start_offset' => 70, 'end_offset' => 74],
        ]]);
        $this->getJson($this->url($document, $sources['value'], $revision->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_AMBIGUOUS');
        $evidence->update(['sources' => [['quote' => '12.4',
            'start_offset' => mb_strpos($revision->extracted_text, '12.4'),
            'end_offset' => mb_strpos($revision->extracted_text, '12.4') + 4, 'span_id' => 'value']]]);
        $row = DB::table('structured_extractions')->where('id', $revision->structured_extraction_id)->firstOrFail();
        $nodes = json_decode($row->nodes, true);
        foreach ($nodes as &$node) {
            if (($node['id'] ?? null) === 'sheet:0/cell:B2') {
                $node['meta']['coordinate'] = 'XFE1048577';
            }
        }
        unset($node);
        DB::table('structured_extractions')->where('id', $revision->structured_extraction_id)
            ->update(['nodes' => json_encode($nodes)]);
        $this->getJson($this->url($document, $sources['value'], $revision->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }

    public function test_truncated_and_oversized_structures_are_bounded_refusals(): void
    {
        [$user, $document, $revision, $sources] = $this->fixture();
        Sanctum::actingAs($user);
        DB::table('structured_extractions')->where('id', $revision->structured_extraction_id)
            ->update(['capabilities' => json_encode(['nodes_truncated' => true, 'cells' => 'partial'])]);
        $this->getJson($this->url($document, $sources['value'], $revision->id))
            ->assertStatus(422)->assertJsonPath('error.code', 'SOURCE_TOO_COMPLEX');
        DB::table('structured_extractions')->where('id', $revision->structured_extraction_id)
            ->update(['capabilities' => json_encode(['nodes_truncated' => false, 'cells' => 'complete']),
                'nodes' => json_encode([['id' => 'oversized', 'raw' => str_repeat('x', 8 * 1024 * 1024)]])]);
        $this->getJson($this->url($document, $sources['value'], $revision->id))
            ->assertStatus(422)->assertJsonPath('error.code', 'SOURCE_TOO_COMPLEX');
    }

    public function test_tenant_personal_owner_classification_and_revoked_access_are_checked(): void
    {
        [$user, $document, $revision, $sources] = $this->fixture();
        $url = $this->url($document, $sources['value'], $revision->id);
        $this->getJson($url)->assertUnauthorized();
        $outsider = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($outsider);
        Sanctum::actingAs($outsider->fresh());
        $this->getJson($url)->assertForbidden();
        Sanctum::actingAs($user);
        $this->getJson($url)->assertOk();
        $document->forceFill(['uploaded_by' => $outsider->id])->save();
        $this->getJson($url)->assertForbidden();

        // Organization membership and classification use the same document
        // policy as source resolution and original downloads.
        $workspace = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'Review']);
        $viewer = User::factory()->create(['role' => 'Viewer']);
        WorkspaceMember::create(['workspace_id' => $workspace->id, 'user_id' => $viewer->id,
            'role' => 'Viewer', 'joined_at' => now()]);
        $viewer->forceFill(['current_workspace_id' => $workspace->id])->save();
        $document->forceFill(['workspace_id' => $workspace->id, 'classification' => 'Restricted'])->save();
        Sanctum::actingAs($viewer->fresh());
        $this->getJson($url)->assertForbidden();
    }

    public function test_route_has_a_real_rate_limit(): void
    {
        [$user, $document, $revision, $sources] = $this->fixture();
        Sanctum::actingAs($user);
        $url = $this->url($document, $sources['value'], $revision->id);
        for ($i = 0; $i < 60; $i++) {
            $this->getJson($url)->assertOk();
        }
        $this->getJson($url)->assertStatus(429);
    }
}
