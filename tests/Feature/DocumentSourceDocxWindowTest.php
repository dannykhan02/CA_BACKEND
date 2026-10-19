<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\User;
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
use Tests\TestCase;

class DocumentSourceDocxWindowTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(?User $owner = null): array
    {
        $user = $owner ?? User::factory()->create();
        if ($owner === null) {
            app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        }
        $user->refresh();
        $path = tempnam(sys_get_temp_dir(), 'docx_window_');
        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('docProps/custom.xml', '<id>'.Str::uuid().'</id>');
        $xml = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>';
        $xml .= '<w:p><w:r><w:t>Opening paragraph</w:t></w:r></w:p>';
        $xml .= '<w:p><w:pPr><w:pStyle w:val="Heading1"/></w:pPr><w:r><w:t>Termination</w:t></w:r></w:p>';
        for ($i = 1; $i <= 9; $i++) {
            $xml .= '<w:p><w:r><w:t>Context paragraph '.$i.' with café 😀 notice.</w:t></w:r></w:p>';
        }
        $xml .= '<w:p/>';
        for ($i = 1; $i <= 3; $i++) {
            $xml .= '<w:p><w:pPr><w:numPr><w:ilvl w:val="1"/><w:numId w:val="7"/></w:numPr></w:pPr><w:r><w:t>List condition '.$i.'</w:t></w:r></w:p>';
        }
        $xml .= '<w:p><w:pPr><w:pStyle w:val="ListBullet"/></w:pPr><w:r><w:t>Style alone is not a list</w:t></w:r></w:p><w:tbl>';
        foreach ([['Metric', 'FY2025'], ['Revenue', '12.4'], ['Expense', '9.1']] as $i => $cells) {
            $xml .= '<w:tr>'.($i === 0 ? '<w:trPr><w:tblHeader/></w:trPr>' : '');
            foreach ($cells as $cell) {
                $xml .= '<w:tc><w:p><w:r><w:t>'.$cell.'</w:t></w:r></w:p></w:tc>';
            }
            $xml .= '</w:tr>';
        }
        $xml .= '</w:tbl><w:p><w:r><w:t>Final paragraph</w:t></w:r></w:p></w:body></w:document>';
        $zip->addFromString('word/document.xml', $xml);
        $zip->close();
        try {
            $structure = app(DocumentTextExtractor::class)->extractDocxStructured($path);
            $fileHash = hash_file('sha256', $path);
        } finally {
            unlink($path);
        }
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Agreement.docx', 'type' => 'DOCX',
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2026, 'file_hash' => $fileHash]);
        app(StructuredExtractionStore::class)->publish($document, $structure);
        $sources = [];
        foreach (['first' => 'Opening paragraph', 'heading' => 'Termination',
            'paragraph' => 'Context paragraph 5 with café 😀 notice.', 'partial' => 'café 😀 notice.',
            'list' => 'List condition 2', 'style' => 'Style alone is not a list',
            'cell' => '12.4', 'row' => 'Revenue | 12.4', 'last' => 'Final paragraph'] as $key => $quote) {
            $start = $key === 'partial' ? mb_strpos($structure->text, $quote, mb_strpos($structure->text, 'Context paragraph 5'))
                : mb_strpos($structure->text, $quote);
            $sources[$key] = 'kpi:'.Str::uuid();
            DocumentEvidence::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'pipeline_key' => 'legacy',
                'identity' => hash('sha256', $key), 'kind' => 'metric', 'source_id' => $sources[$key],
                'data' => ['value' => $key === 'row' ? '12.4' : $quote],
                'sources' => [['quote' => $quote, 'start_offset' => $start,
                    'end_offset' => $start + mb_strlen($quote), 'span_id' => $key]]]);
        }
        $setId = app(TextRoleProvenanceProducer::class)->publish($document);
        $revision = DocumentIntelligenceRevision::create([
            'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
            'generation' => 1, 'state' => 'published', 'file_hash' => $fileHash,
            'extracted_text' => $structure->text, 'extracted_text_hash' => hash('sha256', $structure->text),
            'pipeline_key' => 'legacy', 'ai_pipeline' => ['route' => 'normal', 'key' => 'legacy'],
            'parser_version' => $structure->parserVersion, 'structure_policy' => StructuredDocument::POLICY,
            'structured_extraction_id' => DB::table('structured_extractions')->where('document_id', $document->id)->value('id'),
            'provenance_set_id' => $setId, 'provenance_policy' => SourceProvenance::POLICY,
        ]);
        $document->forceFill(['published_intelligence_revision_id' => $revision->id,
            'intelligence_generation' => 1])->save();
        Sanctum::actingAs($user);

        return [$user, $document, $revision, $sources];
    }

    private function url(Document $doc, string $source, string $revision): string
    {
        return "/api/documents/{$doc->id}/sources/{$source}/docx-window?expected_revision_id={$revision}";
    }

    public function test_paragraph_has_verified_hash_heading_and_bounded_context(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        $response = $this->getJson($this->url($doc, $sources['paragraph'], $rev->id))->assertOk()
            ->assertJsonPath('data.context.target.type', 'paragraph')
            ->assertJsonPath('data.context.sectionHeading.text', 'Termination')
            ->assertJsonCount(3, 'data.context.before')->assertJsonCount(3, 'data.context.after');
        self::assertSame(hash('sha256', $response->json('data.anchor.text')), $response->json('data.anchor.sourceHash'));
        self::assertLessThan(65536, strlen($response->getContent()));
    }

    public function test_partial_repeated_quote_uses_stored_offsets_not_first_text_match(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        $this->getJson($this->url($doc, $sources['partial'], $rev->id))->assertOk()
            ->assertJsonPath('data.context.target.text', 'Context paragraph 5 with café 😀 notice.');
    }

    public function test_first_last_and_heading_sources_resolve_without_optional_neighbors(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        $this->getJson($this->url($doc, $sources['first'], $rev->id))->assertOk()
            ->assertJsonPath('data.context.sectionHeading', null)->assertJsonCount(0, 'data.context.before');
        $this->getJson($this->url($doc, $sources['last'], $rev->id))->assertOk()->assertJsonCount(0, 'data.context.after');
        $this->getJson($this->url($doc, $sources['heading'], $rev->id))->assertOk()
            ->assertJsonPath('data.context.target.type', 'heading')->assertJsonPath('data.context.target.headingLevel', 1);
    }

    public function test_table_cell_and_row_citations_show_owned_rows_and_explicit_headers(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        foreach (['cell', 'row'] as $key) {
            $this->getJson($this->url($doc, $sources[$key], $rev->id))->assertOk()
                ->assertJsonPath('data.context.target.text', '12.4')
                ->assertJsonPath('data.context.target.type', 'cell')
                ->assertJsonPath('data.table.rows.0.header', true)
                ->assertJsonPath('data.table.rows.0.cells.1.text', 'FY2025')
                ->assertJsonPath('data.table.rows.1.cells.1.cited', true)
                ->assertJsonCount(0, 'data.context.before')->assertJsonCount(3, 'data.table.rows');
        }
    }

    public function test_explicit_list_ownership_is_preserved_without_guessing_style_or_markers(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        $this->getJson($this->url($doc, $sources['list'], $rev->id))->assertOk()
            ->assertJsonPath('data.context.target.type', 'list_item')
            ->assertJsonPath('data.context.target.listId', '7')->assertJsonPath('data.context.target.listLevel', 1)
            ->assertJsonCount(1, 'data.context.before')->assertJsonCount(1, 'data.context.after');
        $this->getJson($this->url($doc, $sources['style'], $rev->id))->assertOk()
            ->assertJsonPath('data.context.target.type', 'paragraph');
    }

    public function test_corrupt_hash_including_unbound_context_refuses_source(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        DB::table('source_anchors')->where('set_id', $rev->provenance_set_id)
            ->where('structural_path', 'body/p[2]')->update(['source_hash' => hash('sha256', 'corrupt')]);
        $this->getJson($this->url($doc, $sources['paragraph'], $rev->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }

    public function test_other_document_source_and_unauthorized_user_are_refused(): void
    {
        [$user, $doc, $rev, $sources] = $this->fixture();
        [, $other, $otherRev] = $this->fixture($user);
        Sanctum::actingAs($user);
        $this->getJson($this->url($other, $sources['paragraph'], $otherRev->id))->assertNotFound();
        Sanctum::actingAs(User::factory()->create());
        $this->getJson($this->url($doc, $sources['paragraph'], $rev->id))->assertForbidden();
    }

    public function test_wrong_revision_file_and_pinned_extraction_are_refused(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        $this->getJson($this->url($doc, $sources['paragraph'], (string) Str::uuid()))
            ->assertStatus(409)->assertJsonPath('error.code', 'STALE_SOURCE_REFERENCE');
        $original = $doc->file_hash;
        $doc->forceFill(['file_hash' => hash('sha256', 'replacement')])->save();
        $this->getJson($this->url($doc, $sources['paragraph'], $rev->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
        $doc->forceFill(['file_hash' => $original])->save();
        DB::table('document_intelligence_revisions')->where('id', $rev->id)->update(['structured_extraction_id' => null]);
        $this->getJson($this->url($doc, $sources['paragraph'], $rev->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }

    public function test_missing_and_malformed_locator_and_projection_fail_safely(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        $stored = DB::table('structured_extractions')->where('id', $rev->structured_extraction_id)->value('nodes');
        foreach (['missing', 'path', 'text'] as $fault) {
            $nodes = json_decode($stored, true);
            foreach ($nodes as $i => &$node) {
                if (($node['id'] ?? '') === 'body/p[7]') {
                    if ($fault === 'missing') {
                        unset($nodes[$i]);
                    } elseif ($fault === 'path') {
                        $node['path'] = 'wrong/path';
                    } else {
                        $node['text'] = 'unrelated text';
                    }
                }
            }
            unset($node);
            DB::table('structured_extractions')->where('id', $rev->structured_extraction_id)->update(['nodes' => json_encode(array_values($nodes))]);
            $this->getJson($this->url($doc, $sources['paragraph'], $rev->id))
                ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
        }
    }

    public function test_large_structure_and_missing_revision_parameter_are_bounded_refusals(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        $this->getJson("/api/documents/{$doc->id}/sources/{$sources['paragraph']}/docx-window")
            ->assertStatus(422)->assertJsonPath('error.code', 'INVALID_SOURCE_REVISION');
        DB::table('structured_extractions')->where('id', $rev->structured_extraction_id)
            ->update(['nodes' => json_encode([['id' => 'huge', 'text' => str_repeat('x', 8 * 1024 * 1024)]])]);
        $this->getJson($this->url($doc, $sources['paragraph'], $rev->id))
            ->assertStatus(422)->assertJsonPath('error.code', 'SOURCE_TOO_COMPLEX');
    }

    public function test_native_anchor_locator_corruption_cannot_fall_back_to_a_valid_raw_quote(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        DB::table('source_anchors')->where('set_id', $rev->provenance_set_id)
            ->where('structural_path', 'body/p[7]')->update(['structural_path' => 'body/p[999]']);
        $this->getJson($this->url($doc, $sources['paragraph'], $rev->id))
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_UNAVAILABLE');
    }

    public function test_older_extraction_without_list_metadata_still_supports_paragraphs(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        $nodes = json_decode(DB::table('structured_extractions')->where('id', $rev->structured_extraction_id)->value('nodes'), true);
        foreach ($nodes as &$node) {
            unset($node['meta']['list_id'], $node['meta']['list_level']);
        }
        unset($node);
        DB::table('structured_extractions')->where('id', $rev->structured_extraction_id)
            ->update(['parser_version' => 'wordprocessingml-body.v4', 'nodes' => json_encode($nodes)]);
        DB::table('document_intelligence_revisions')->where('id', $rev->id)
            ->update(['parser_version' => 'wordprocessingml-body.v4']);
        $this->getJson($this->url($doc, $sources['list'], $rev->id))->assertOk()
            ->assertJsonPath('data.context.target.type', 'paragraph');
    }

    public function test_response_names_what_this_rendering_leaves_out(): void
    {
        // The fixture has lists, no footnotes, and the parser never reads
        // headers, comments or endnotes. `text_box_text` must be absent: a
        // text box's words are extracted into its body paragraph, so calling
        // them excluded would tell the reader something false. See
        // tests/Unit/Services/DocxExcludedContentTest.php for the proof of
        // each entry.
        [, $doc, $rev, $sources] = $this->fixture();
        $excluded = $this->getJson($this->url($doc, $sources['paragraph'], $rev->id))->assertOk()
            ->json('data.excluded');

        self::assertSame([
            'comments',
            'endnotes',
            'field_codes',
            'headers_and_footers',
            'images',
            'list_markers',
            'tracked_change_deletions',
        ], $excluded);
        self::assertNotContains('text_box_text', $excluded);
        self::assertNotContains('footnote_text', $excluded, 'this fixture carries no footnotes');
    }

    public function test_footnote_text_is_named_only_when_the_document_has_footnotes(): void
    {
        [, $doc, $rev, $sources] = $this->fixture();
        $capabilities = json_decode(DB::table('structured_extractions')
            ->where('id', $rev->structured_extraction_id)->value('capabilities'), true);
        $capabilities['footnotes'] = 'referenced_source_nodes';
        DB::table('structured_extractions')->where('id', $rev->structured_extraction_id)
            ->update(['capabilities' => json_encode($capabilities)]);

        self::assertContains('footnote_text', $this->getJson($this->url($doc, $sources['paragraph'], $rev->id))
            ->assertOk()->json('data.excluded'));
    }

    public function test_existing_response_fields_are_unchanged_by_the_additive_excluded_list(): void
    {
        // `excluded` is additive. Nothing that was in the response before may
        // have moved, so a frontend that does not know the field is unaffected.
        [, $doc, $rev, $sources] = $this->fixture();
        $this->getJson($this->url($doc, $sources['paragraph'], $rev->id))->assertOk()
            ->assertJsonStructure(['data' => ['type', 'documentId', 'sourceId',
                'intelligenceRevisionId', 'sourceRevision', 'excluded',
                'anchor' => ['blockId', 'blockType', 'sourceHash', 'id', 'text', 'range', 'quote', 'quoteRange'],
                'context' => ['sectionHeading', 'before', 'target', 'after']]])
            ->assertJsonPath('data.type', 'docx')
            ->assertJsonPath('data.context.target.type', 'paragraph');
    }
}
