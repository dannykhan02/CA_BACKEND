<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\User;
use App\Services\Extraction\StructuredDocument;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\SourceProvenance;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The production shape (document 01a158bd-f245-734e-b3f0-51fa483f4ec9,
 * published revision generation 1): a normal-route finding is stored as a
 * text_span anchor `raw:265:355` with no sheet and no structural path, while a
 * worksheet_cell anchor for the same text exists at `sheet:0!A9`, offsets
 * 265-347. The span overruns the cell by 8 characters, because the extracted
 * row text appends the " | " separators of its empty trailing cells. A second
 * finding cites a whole row of three adjacent cells.
 *
 * This fixture reproduces both with the real separator layout:
 * cells joined by " | ", rows by "\n" (SpreadsheetTextExtractor.php:159,180).
 */
class DocumentSourceCellWindowSpanOverlapTest extends TestCase
{
    use RefreshDatabase;

    /** Only non-empty cells become nodes, exactly as the extractor projects them. */
    private const ROWS = [
        2 => ['Issue', 'Category', 'Status', 'Owner'],
        3 => ['Telecoms licensing delays above 30 days', 'Licensing', 'Under review', ''],
        4 => ['Spectrum renewal fees rose 12 percent', '', '', ''],
    ];

    private function fixture(): array
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();

        $lines = ['=== Sheet: Performance ==='];
        foreach (self::ROWS as $cells) {
            $lines[] = implode(' | ', $cells);
        }
        $text = implode("\n", $lines);
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Performance.xlsx', 'type' => 'XLSX',
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2026, 'file_hash' => hash('sha256', 'performance workbook')]);
        $nodes = [['id' => 'sheet:0', 'type' => 'worksheet', 'sheet' => 'Performance', 'path' => 'sheet:0',
            'meta' => ['order' => 0, 'visibility' => 'visible', 'date_system' => 1900]]];
        foreach ($this->cellRanges($text) as $coordinate => [$start, $end, $value]) {
            $nodes[] = ['id' => 'sheet:0/cell:'.$coordinate, 'type' => 'cell', 'parent' => 'sheet:0',
                'sheet' => 'Performance', 'path' => 'sheet:0!'.$coordinate,
                'start' => $start, 'end' => $end, 'text' => $value,
                'meta' => ['coordinate' => $coordinate, 'cell_type' => 's', 'raw' => $value,
                    'display' => $value, 'formula' => null, 'cached_result' => null,
                    'cache_present' => false, 'number_format' => 'General', 'rich_text' => false,
                    'merges' => [], 'sheet_visibility' => 'visible', 'row_visible' => true,
                    'row_visible_after_filter' => true, 'column_visible' => true]];
        }
        app(StructuredExtractionStore::class)->publish($document,
            new StructuredDocument('XLSX', 'fixture.v1', $text, $nodes,
                ['nodes_truncated' => false, 'cells' => 'complete']));

        $sources = [];
        foreach ($this->citations($text) as $name => [$start, $end]) {
            $sources[$name] = 'deadline:'.Str::uuid();
            $quote = mb_substr($text, $start, $end - $start);
            // A normal-route source proves an occurrence, not a field role:
            // `data` carries the quote only (NormalRouteProvenance::record()).
            DocumentEvidence::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'pipeline_key' => 'legacy',
                'identity' => hash('sha256', $name), 'kind' => 'normal_source',
                'source_id' => $sources[$name], 'data' => ['quote' => $quote],
                'sources' => [['span_id' => 'E001', 'quote' => $quote, 'page' => null,
                    'start_offset' => $start, 'end_offset' => $end]]]);
        }
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        $revision = DocumentIntelligenceRevision::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'generation' => 1, 'state' => 'published', 'file_hash' => $document->file_hash,
            'extracted_text' => $text, 'extracted_text_hash' => hash('sha256', $text),
            'pipeline_key' => 'legacy', 'ai_pipeline' => ['key' => 'legacy'],
            'parser_version' => 'fixture.v1', 'structure_policy' => StructuredDocument::POLICY,
            'structured_extraction_id' => DB::table('structured_extractions')
                ->where('document_id', $document->id)->value('id'),
            'provenance_set_id' => $setId, 'provenance_policy' => SourceProvenance::POLICY,
        ]);
        $document->forceFill(['published_intelligence_revision_id' => $revision->id,
            'intelligence_generation' => 1])->save();
        Sanctum::actingAs($user);

        return [$user, $document, $revision, $sources, $text];
    }

    /** @return array<string, array{int, int, string}> */
    private function cellRanges(string $text): array
    {
        $ranges = [];
        foreach (self::ROWS as $row => $cells) {
            $line = implode(' | ', $cells);
            $lineStart = mb_strpos($text, $line);
            $within = 0;
            foreach ($cells as $index => $value) {
                if ($value !== '') {
                    $ranges[chr(ord('A') + $index).$row] = [$lineStart + $within,
                        $lineStart + $within + mb_strlen($value), $value];
                }
                $within += mb_strlen($value) + 3;
            }
        }

        return $ranges;
    }

    /** @return array<string, array{int, int}> */
    private function citations(string $text): array
    {
        $cells = $this->cellRanges($text);
        $wholeRow = implode(' | ', self::ROWS[4]);
        $rowStart = mb_strpos($text, $wholeRow);

        return [
            // One cell plus the separators of the empty cells after it: the
            // production span, 8 characters past the end of its only cell.
            'overrun' => [$cells['A4'][0], $rowStart + mb_strlen(rtrim($wholeRow))],
            // Three adjacent cells with their separators between them.
            'row' => [$cells['A3'][0], $cells['C3'][1]],
            // Negative: the sheet header line is real text outside any cell.
            'header' => [mb_strpos($text, '=== Sheet'), $cells['B2'][1]],
            // Negative: no cell overlaps this span at all.
            'nocell' => [mb_strpos($text, 'Sheet: Performance'), mb_strpos($text, 'Sheet: Performance') + 18],
            // Negative: the span cuts the first cell in half.
            'partial' => [$cells['A3'][0] + 9, $cells['C3'][1]],
        ];
    }

    private function url(Document $document, string $sourceId, string $revisionId): string
    {
        return "/api/documents/{$document->id}/sources/{$sourceId}/cell-window?expected_revision_id={$revisionId}";
    }

    public function test_the_production_span_overruns_its_only_cell_by_eight_separator_characters(): void
    {
        [, , $revision, , $text] = $this->fixture();
        [$start, $end] = $this->citations($text)['overrun'];
        $cell = $this->cellRanges($text)['A4'];

        self::assertSame($cell[0], $start);
        self::assertSame(8, $end - $cell[1]);
        self::assertSame(' |  |  |', mb_substr($text, $cell[1], 8));

        // The stored anchors are the production pair: a text_span for the
        // citation, a worksheet_cell for the cell it overruns.
        $anchors = DB::table('source_anchors')->where('set_id', $revision->provenance_set_id)
            ->whereIn('source_key', ['raw:'.$start.':'.$end, 'node:'.hash('sha256', 'sheet:0/cell:A4')])
            ->orderBy('kind')->get(['kind', 'sheet', 'structural_path', 'start_offset', 'end_offset']);
        self::assertSame(['text_span', 'worksheet_cell'], $anchors->pluck('kind')->sort()->values()->all());
        $span = $anchors->firstWhere('kind', 'text_span');
        self::assertNull($span->sheet);
        self::assertNull($span->structural_path);
        self::assertSame([$start, $end], [$span->start_offset, $span->end_offset]);
        $worksheetCell = $anchors->firstWhere('kind', 'worksheet_cell');
        self::assertSame('sheet:0!A4', $worksheetCell->structural_path);
        self::assertSame([$cell[0], $cell[1]], [$worksheetCell->start_offset, $worksheetCell->end_offset]);
    }

    public function test_a_span_that_overruns_one_cell_by_separators_resolves_to_that_cell(): void
    {
        [, $document, $revision, $sources] = $this->fixture();

        $response = $this->getJson($this->url($document, $sources['overrun'], $revision->id))->assertOk();
        $response->assertJsonPath('data.sheet.name', 'Performance')
            ->assertJsonPath('data.focus.coordinate', 'A4')
            ->assertJsonPath('data.citation.resolution', 'OVERLAPPED_CELLS')
            ->assertJsonPath('data.citation.coversMultipleCells', false)
            ->assertJsonPath('data.citation.coveredCells', ['A4']);
        $cells = collect($response->json('data.window.rows'))->flatMap(fn ($row) => $row['cells'])->keyBy('coordinate');
        self::assertSame('Spectrum renewal fees rose 12 percent', $cells['A4']['rawValue']);
    }

    public function test_a_whole_row_citation_centres_on_its_first_cell_and_lists_every_cell_it_covers(): void
    {
        [, $document, $revision, $sources] = $this->fixture();

        $this->getJson($this->url($document, $sources['row'], $revision->id))->assertOk()
            ->assertJsonPath('data.focus.coordinate', 'A3')
            ->assertJsonPath('data.citation.resolution', 'OVERLAPPED_CELLS')
            ->assertJsonPath('data.citation.coversMultipleCells', true)
            ->assertJsonPath('data.citation.coveredCells', ['A3', 'B3', 'C3']);
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvableSpans(): iterable
    {
        yield 'real text outside any cell' => ['header'];
        yield 'no cell overlaps the span' => ['nocell'];
        yield 'the span cuts a cell in half' => ['partial'];
    }

    #[DataProvider('unresolvableSpans')]
    public function test_a_span_that_is_not_separator_clean_stays_unavailable(string $name): void
    {
        [, $document, $revision, $sources] = $this->fixture();

        $this->getJson($this->url($document, $sources[$name], $revision->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }

    public function test_cells_on_different_sheets_are_never_one_window(): void
    {
        [, $document, $revision, $sources] = $this->fixture();
        // Guarded for its own sake: the extractor's own "=== Sheet: x ==="
        // line always separates two sheets, so a cross-sheet span is rejected
        // as foreign text first. Move one covered cell to another sheet and
        // the overlap rule itself must still refuse the pair.
        $row = DB::table('structured_extractions')->where('id', $revision->structured_extraction_id)->firstOrFail();
        $nodes = json_decode($row->nodes, true);
        foreach ($nodes as &$node) {
            if (($node['id'] ?? null) === 'sheet:0/cell:B3') {
                $node['sheet'] = 'Second';
            }
        }
        unset($node);
        DB::table('structured_extractions')->where('id', $revision->structured_extraction_id)
            ->update(['nodes' => json_encode($nodes)]);

        $this->getJson($this->url($document, $sources['row'], $revision->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }

    public function test_an_overlap_resolution_is_still_pinned_to_the_published_revision(): void
    {
        [, $document, $revision, $sources] = $this->fixture();

        $this->getJson($this->url($document, $sources['overrun'], (string) Str::uuid()))
            ->assertStatus(409)->assertJsonPath('error.code', 'STALE_SOURCE_REFERENCE');

        DB::table('structured_extractions')->where('id', $revision->structured_extraction_id)
            ->update(['text_hash' => hash('sha256', 'another extraction')]);
        $this->getJson($this->url($document, $sources['overrun'], $revision->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }
}
