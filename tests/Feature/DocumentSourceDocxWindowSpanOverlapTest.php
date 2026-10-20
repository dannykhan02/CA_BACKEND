<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\User;
use App\Services\AI\Incremental\SourceSpanBuilder;
use App\Services\DocumentTextExtractor;
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
 * DOCX citations had never resolved in production: the only DOCX with a
 * published revision (01a14589-8870-7247-848f-a715a20ab044, generated
 * 2026-10-16, before 260d730 captured normal-route spans) has 0 source
 * anchors, so there was nothing to resolve against and nothing to observe.
 *
 * These fixtures are built from a Word-like structure — a heading, short
 * paragraphs, a table with a header row and an empty leading cell, and a
 * paragraph with an inline image — through the real extractor, and cited the
 * way the normal route cites: one span per evidence row, no field roles.
 */
class DocumentSourceDocxWindowSpanOverlapTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(): array
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $path = tempnam(sys_get_temp_dir(), 'docx_overlap_');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $xml = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"'
            .' xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"'
            .' xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"><w:body>'
            .'<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Contract Overview</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>The parties agree as follows.</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Notice must be given in writing.</w:t></w:r></w:p>'
            .'<w:tbl><w:tr><w:trPr><w:tblHeader/></w:trPr>'
            .'<w:tc><w:p><w:r><w:t>Metric</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>Category</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>Status</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:p><w:r><w:t>Telecoms licensing delays above 30 days</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>Licensing</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>Under review</w:t></w:r></w:p></w:tc></w:tr>'
            .'<w:tr><w:tc><w:p/></w:tc>'
            .'<w:tc><w:p><w:r><w:t>Spectrum</w:t></w:r></w:p></w:tc>'
            .'<w:tc><w:p><w:r><w:t>Pending</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
            // An inline image contributes no text at all: its alt text lives in
            // a namespace extractWordXml never reads, which is why the window's
            // `excluded` list has to say "images".
            .'<w:p><w:r><w:drawing><wp:inline><a:graphic><a:graphicData>'
            .'<pic:pic xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"><pic:nvPicPr>'
            .'<pic:cNvPr id="1" name="chart.png" descr="Quarterly revenue chart"/>'
            .'</pic:nvPicPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>'
            .'<w:r><w:t>See the chart above for the trend.</w:t></w:r></w:p>'
            .'<w:p><w:r><w:t>Final paragraph</w:t></w:r></w:p></w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        try {
            $structure = app(DocumentTextExtractor::class)->extractDocxStructured($path);
            $fileHash = hash_file('sha256', $path);
        } finally {
            unlink($path);
        }
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Overview.docx', 'type' => 'DOCX',
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2026, 'file_hash' => $fileHash]);
        app(StructuredExtractionStore::class)->publish($document, $structure);

        $sources = [];
        foreach ($this->citations($structure) as $name => [$start, $end]) {
            $sources[$name] = 'deadline:'.Str::uuid();
            // The normal route proves an occurrence, not a field role:
            // `data` carries the quote only, so nothing binds a value role.
            DocumentEvidence::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'pipeline_key' => 'legacy',
                'identity' => hash('sha256', $name), 'kind' => 'normal_source',
                'source_id' => $sources[$name],
                'data' => ['quote' => mb_substr($structure->text, $start, $end - $start)],
                'sources' => [['span_id' => 'E001', 'page' => null,
                    'quote' => mb_substr($structure->text, $start, $end - $start),
                    'start_offset' => $start, 'end_offset' => $end]]]);
        }
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        $revision = DocumentIntelligenceRevision::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'generation' => 1, 'state' => 'published', 'file_hash' => $fileHash,
            'extracted_text' => $structure->text, 'extracted_text_hash' => hash('sha256', $structure->text),
            'pipeline_key' => 'legacy', 'ai_pipeline' => ['route' => 'normal', 'key' => 'legacy'],
            'parser_version' => $structure->parserVersion, 'structure_policy' => StructuredDocument::POLICY,
            'structured_extraction_id' => DB::table('structured_extractions')
                ->where('document_id', $document->id)->value('id'),
            'provenance_set_id' => $setId, 'provenance_policy' => SourceProvenance::POLICY,
        ]);
        $document->forceFill(['published_intelligence_revision_id' => $revision->id,
            'intelligence_generation' => 1])->save();
        Sanctum::actingAs($user);

        return [$user, $document, $revision, $sources, $structure];
    }

    /** @return array<string, array{int, int}> */
    private function citations(StructuredDocument $structure): array
    {
        $text = $structure->text;
        $node = fn (string $id): array => collect($structure->nodes)->firstWhere('id', $id);
        $at = function (string $needle) use ($text): array {
            $start = mb_strpos($text, $needle);

            return [$start, $start + mb_strlen($needle)];
        };
        $heading = $node('body/p[1]');
        $first = $node('body/p[2]');
        $second = $node('body/p[3]');
        $row = $at('Telecoms licensing delays above 30 days | Licensing | Under review');
        $emptyLeadingCell = $at(' | Spectrum | Pending');

        return [
            // (a) a whole paragraph and the "\n" the extractor puts after it.
            'paragraph_newline' => [$second['start'], $second['end'] + 1],
            // (b) a table row joined with " | ", and the same with an empty
            // leading cell, where the span starts on the separator itself.
            'row' => $row,
            'row_empty_leading_cell' => $emptyLeadingCell,
            // (c) two paragraphs, which is one SourceSpanBuilder prose block.
            'two_paragraphs' => [$first['start'], $second['end']],
            // The heading grouped with the short paragraphs under it.
            'heading_and_paragraphs' => [$heading['start'], $second['end']],
            'image_paragraph' => $at('See the chart above for the trend.'),
            // Negative: the span cuts the paragraph after the one it covers.
            'cut_block' => [$first['start'], $second['start'] + 6],
            // Negative: real text — a whole table row — outside every block
            // the span covers whole.
            'text_outside_blocks' => [$second['start'], $at('Metric | Category | Status')[1]],
            // Negative: cells belonging to two different rows.
            'two_rows' => [$row[0], $emptyLeadingCell[1]],
        ];
    }

    private function url(Document $document, string $sourceId, string $revisionId): string
    {
        return "/api/documents/{$document->id}/sources/{$sourceId}/docx-window?expected_revision_id={$revisionId}";
    }

    public function test_a_normal_route_docx_analysis_publishes_structural_anchors_but_no_field_roles(): void
    {
        [, $document, $revision, , $structure] = $this->fixture();
        $anchors = DB::table('source_anchors')->where('set_id', $revision->provenance_set_id)->get();

        self::assertSame(['cell', 'column_header', 'paragraph', 'row_header', 'section_heading',
            'table', 'table_column', 'table_row', 'text_span'],
            $anchors->pluck('kind')->unique()->sort()->values()->all());
        self::assertSame(['docx'], $anchors->pluck('modality')->unique()->values()->all());
        self::assertSame('body/p[3]', $anchors->firstWhere('structural_path', 'body/p[3]')->structural_path);
        self::assertNotNull($anchors->firstWhere('structural_path', 'body/tbl[1]/tr[3]/tc[2]'));
        // A container has no projection of its own, so it carries no hash.
        $row = $anchors->firstWhere('structural_path', 'body/tbl[1]/tr[2]');
        self::assertNull($row->start_offset);
        self::assertNull($row->source_hash);
        // The citation's own span anchor has no sheet, page or structural path.
        $span = $anchors->firstWhere('kind', 'text_span');
        self::assertNull($span->sheet);
        // Nothing binds a role: a normal-route source has no data.value, so
        // neither producer can claim a value cell for it.
        self::assertSame(0, DB::table('evidence_role_bindings')
            ->where('document_id', $document->id)->count());

        // The spans the normal route actually cuts are wider than one block:
        // short paragraphs are grouped into one prose span.
        $spans = app(SourceSpanBuilder::class)->build($structure->text, null);
        self::assertSame('Contract Overview'."\n".'The parties agree as follows.'."\n"
            .'Notice must be given in writing.',
            mb_substr($structure->text, $spans[0]['start_offset'],
                $spans[0]['end_offset'] - $spans[0]['start_offset']));
    }

    public function test_a_paragraph_citation_that_keeps_its_trailing_newline_resolves_to_that_paragraph(): void
    {
        [, $document, $revision, $sources] = $this->fixture();

        $this->getJson($this->url($document, $sources['paragraph_newline'], $revision->id))->assertOk()
            ->assertJsonPath('data.context.target.id', 'body/p[3]')
            ->assertJsonPath('data.context.target.type', 'paragraph')
            ->assertJsonPath('data.context.target.text', 'Notice must be given in writing.')
            ->assertJsonPath('data.citation.resolution', 'OVERLAPPED_BLOCKS')
            ->assertJsonPath('data.citation.coversMultipleBlocks', false)
            ->assertJsonPath('data.citation.coveredBlockIds', ['body/p[3]']);
    }

    public function test_a_citation_over_two_paragraphs_centres_on_the_first_and_names_both(): void
    {
        [, $document, $revision, $sources] = $this->fixture();

        $this->getJson($this->url($document, $sources['two_paragraphs'], $revision->id))->assertOk()
            ->assertJsonPath('data.context.target.id', 'body/p[2]')
            ->assertJsonPath('data.citation.coversMultipleBlocks', true)
            ->assertJsonPath('data.citation.coveredBlockIds', ['body/p[2]', 'body/p[3]']);
    }

    public function test_a_heading_grouped_with_its_paragraphs_resolves_to_the_heading_and_both(): void
    {
        [, $document, $revision, $sources] = $this->fixture();

        $this->getJson($this->url($document, $sources['heading_and_paragraphs'], $revision->id))->assertOk()
            ->assertJsonPath('data.context.target.id', 'body/p[1]')
            ->assertJsonPath('data.context.target.type', 'heading')
            ->assertJsonPath('data.citation.coveredBlockIds', ['body/p[1]', 'body/p[2]', 'body/p[3]']);
    }

    public function test_a_row_citation_resolves_whether_or_not_its_leading_cell_is_empty(): void
    {
        [, $document, $revision, $sources] = $this->fixture();

        $this->getJson($this->url($document, $sources['row'], $revision->id))->assertOk()
            ->assertJsonPath('data.context.target.id', 'body/tbl[1]/tr[2]')
            ->assertJsonPath('data.context.target.type', 'table_row')
            ->assertJsonPath('data.table.focusRowId', 'body/tbl[1]/tr[2]')
            ->assertJsonPath('data.table.rows.0.header', true)
            ->assertJsonPath('data.citation.coveredBlockIds', ['body/tbl[1]/tr[2]/tc[1]',
                'body/tbl[1]/tr[2]/tc[2]', 'body/tbl[1]/tr[2]/tc[3]']);

        // The empty cell has no projection, so it is not one of the cells the
        // citation covers — the two that carry text are.
        $this->getJson($this->url($document, $sources['row_empty_leading_cell'], $revision->id))->assertOk()
            ->assertJsonPath('data.context.target.id', 'body/tbl[1]/tr[3]')
            ->assertJsonPath('data.context.target.type', 'table_row')
            ->assertJsonPath('data.citation.coveredBlockIds', ['body/tbl[1]/tr[3]/tc[2]',
                'body/tbl[1]/tr[3]/tc[3]']);
    }

    public function test_a_paragraph_holding_an_inline_image_resolves_and_still_declares_the_image_missing(): void
    {
        [, $document, $revision, $sources] = $this->fixture();

        $response = $this->getJson($this->url($document, $sources['image_paragraph'], $revision->id))
            ->assertOk()->assertJsonPath('data.context.target.id', 'body/p[4]')
            ->assertJsonPath('data.context.target.text', 'See the chart above for the trend.')
            ->assertJsonPath('data.citation.resolution', 'EXACT_BLOCK');
        self::assertContains('images', $response->json('data.excluded'));
        // The image's own name and alt text are not in the extraction at all.
        self::assertStringNotContainsString('chart.png', $response->getContent());
        self::assertStringNotContainsString('Quarterly revenue chart', $response->getContent());
    }

    /** @return iterable<string, array{string}> */
    public static function unresolvableSpans(): iterable
    {
        yield 'the span cuts the next block in half' => ['cut_block'];
        yield 'real text outside every covered block' => ['text_outside_blocks'];
        yield 'cells from two different rows' => ['two_rows'];
    }

    #[DataProvider('unresolvableSpans')]
    public function test_a_span_that_is_not_separator_clean_stays_unavailable(string $name): void
    {
        [, $document, $revision, $sources] = $this->fixture();

        $this->getJson($this->url($document, $sources[$name], $revision->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }

    public function test_an_overlap_resolution_is_still_pinned_and_hash_verified(): void
    {
        [, $document, $revision, $sources] = $this->fixture();

        $this->getJson($this->url($document, $sources['two_paragraphs'], (string) Str::uuid()))
            ->assertStatus(409)->assertJsonPath('error.code', 'STALE_SOURCE_REFERENCE');

        DB::table('source_anchors')->where('set_id', $revision->provenance_set_id)
            ->where('structural_path', 'body/p[2]')->update(['source_hash' => hash('sha256', 'corrupt')]);
        $this->getJson($this->url($document, $sources['two_paragraphs'], $revision->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }
}
