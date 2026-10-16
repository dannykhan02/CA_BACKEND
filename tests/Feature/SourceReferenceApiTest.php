<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\DocumentEvidence;
use App\Models\DocumentRisk;
use App\Models\User;
use App\Services\Extraction\StructuredDocument;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\TextRoleProvenanceProducer;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SourceReferenceApiTest extends TestCase
{
    use RefreshDatabase;

    private function fixture(bool $formula = false, bool $hidden = false): array
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $text = "Metric | FY2025\nRevenue | 12.4";
        $document = Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Results.xlsx', 'type' => 'XLSX',
            'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2025, 'file_hash' => hash('sha256', 'fixture')]);
        $nodes = [['id' => 'sheet:0', 'type' => 'worksheet', 'sheet' => 'Results', 'path' => 'sheet:0',
            'meta' => ['visibility' => 'visible']],
            ['id' => 'sheet:0/table:Results', 'type' => 'table', 'parent' => 'sheet:0',
                'sheet' => 'Results', 'path' => 'sheet:0/table:Results',
                'meta' => ['range' => 'A1:B2', 'header_row' => true]]];
        foreach (['A1' => 'Metric', 'B1' => 'FY2025', 'A2' => 'Revenue', 'B2' => '12.4'] as $cell => $value) {
            $at = mb_strpos($text, $value);
            $nodes[] = ['id' => 'sheet:0/cell:'.$cell, 'type' => 'cell', 'parent' => 'sheet:0',
                'sheet' => 'Results', 'path' => 'sheet:0!'.$cell,
                'start' => $at, 'end' => $at + mb_strlen($value), 'text' => $value,
                'meta' => ['coordinate' => $cell, 'sheet_visibility' => 'visible',
                    'row_visible' => true, 'column_visible' => true,
                    'formula' => $cell === 'B2' && $formula ? '=SUM(6.2,6.2)' : null,
                    'cache_present' => $cell === 'B2' && $formula,
                    'row_visible' => ! ($cell === 'B2' && $hidden), 'merges' => []]];
        }
        app(StructuredExtractionStore::class)->publish($document,
            new StructuredDocument('XLSX', 'fixture.v1', $text, $nodes, []));
        $sourceId = 'kpi:'.(string) Str::uuid();
        $quote = $formula || $hidden ? '12.4' : 'Revenue | 12.4';
        DocumentEvidence::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'pipeline_key' => 'legacy',
            'identity' => hash('sha256', 'revenue'), 'kind' => 'metric', 'source_id' => $sourceId,
            'data' => ['value' => '12.4', 'period' => 'FY2025', 'quote' => $quote],
            'sources' => [['quote' => $quote, 'start_offset' => mb_strpos($text, $quote),
                'end_offset' => mb_strpos($text, $quote) + mb_strlen($quote),
                'span_id' => 'fixture']]]);
        app(TextRoleProvenanceProducer::class)->publish($document);

        return [$user, $document, $sourceId];
    }

    public function test_authorized_exact_source_with_bounded_structural_roles(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        Sanctum::actingAs($user);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $response = $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId);
        self::assertNotEmpty($response->json('data.roles.value'));
        self::assertNotEmpty($response->json('data.relationships'));
        $response->assertOk()->assertJsonPath('data.capability', 'STRUCTURAL_PARTIAL')
            ->assertJsonPath('data.roles.value.0.path', 'sheet:0!B2')
            ->assertJsonPath('data.roles.value.0.metadata.value_origin', 'literal')
            ->assertJsonPath('data.roles.period.0.path', 'sheet:0!B1')
            ->assertJsonPath('data.quality.sourceChannel', 'xlsx')
            ->assertJsonPath('data.quality.structuralRelationshipQuality', 'verified_partial')
            ->assertJsonPath('data.quality.evidenceStatus', 'supported_structural_partial');
        self::assertLessThanOrEqual(24, count($response->json('data.relationships')));
        self::assertLessThanOrEqual(25, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_cross_workspace_and_deleted_document_deny_source_resolution(): void
    {
        [, $document, $sourceId] = $this->fixture();
        $outsider = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($outsider);
        Sanctum::actingAs($outsider->fresh());
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)->assertForbidden();
        Sanctum::actingAs(User::findOrFail($document->uploaded_by));
        $document->delete();
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)->assertNotFound();
    }

    public function test_old_pipeline_reference_is_typed_stale_without_fuzzy_remapping(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        $document->forceFill(['ai_pipeline' => ['key' => hash('sha256', 'new pipeline')]])->save();
        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertStatus(409)->assertJsonPath('error.code', 'STALE_SOURCE_REFERENCE');
    }

    public function test_formula_cache_and_hidden_cell_location_remain_textual_with_origin_metadata(): void
    {
        [$user, $document, $sourceId] = $this->fixture(formula: true, hidden: true);
        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertOk()->assertJsonPath('data.capability', 'TEXTUAL')
            ->assertJsonPath('data.location.path', 'sheet:0!B2')
            ->assertJsonPath('data.location.metadata.row_visible', false)
            ->assertJsonPath('data.location.metadata.value_origin', 'workbook_formula_cache');
    }

    public function test_access_is_rechecked_after_the_reference_was_issued(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)->assertOk();
        $document->forceFill(['uploaded_by' => User::factory()->create()->id])->save();
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)->assertForbidden();
    }

    public function test_processing_document_does_not_expose_candidate_source(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        $document->forceFill(['status' => 'Processing'])->save();
        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_NOT_READY');
    }

    public function test_legacy_typed_evidence_has_bounded_truthful_textual_location(): void
    {
        [$user, $document] = $this->fixture();
        $risk = DocumentRisk::create(['workspace_id' => $document->workspace_id,
            'document_id' => $document->id, 'title' => 'Review needed',
            'description' => 'Source detail', 'severity' => 'low', 'confidence' => 0.9,
            'evidence' => str_repeat('Source detail ', 100), 'status' => 'open',
            'prompt_version' => 'fixture']);
        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/risk:'.$risk->id)
            ->assertOk()->assertJsonPath('data.capability', 'TEXTUAL')
            ->assertJsonPath('data.source.truncated', true)
            ->assertJsonPath('data.source.range', null)
            ->assertJsonPath('data.location', null)
            ->assertJsonPath('data.quality.evidenceStatus', 'unresolved');
    }

    public function test_multiple_source_occurrences_are_not_resolved_to_an_arbitrary_first_match(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        $evidence = DocumentEvidence::where('document_id', $document->id)
            ->where('source_id', $sourceId)->firstOrFail();
        $evidence->update(['sources' => [
            ['quote' => '12.4', 'start_offset' => 0, 'end_offset' => 4],
            ['quote' => '12.4', 'start_offset' => 27, 'end_offset' => 31],
        ]]);
        Sanctum::actingAs($user);

        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertStatus(409)->assertJsonPath('error.code', 'SOURCE_AMBIGUOUS');
    }

    public function test_unresolved_conflict_is_visible_without_promoting_source_quality(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        $evidence = DocumentEvidence::where('document_id', $document->id)
            ->where('source_id', $sourceId)->firstOrFail();
        $evidence->update(['data' => [...$evidence->data,
            'source_conflict' => ['status' => 'unresolved_conflict', 'group' => 'fixture']]]);
        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertOk()->assertJsonPath('data.quality.conflictStatus', 'unresolved_conflict')
            ->assertJsonPath('data.quality.evidenceStatus', 'unresolved');
    }

    /**
     * The bounded-role ceiling is a real refusal, not a truncation: past
     * SourceReferenceResolver::MAX_ROLES the resolver cannot say which
     * anchors it dropped, so it returns nothing rather than a partial answer
     * the reader would take as the whole binding.
     */
    public function test_source_beyond_the_bounded_role_ceiling_is_refused_as_too_complex(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        $evidence = DocumentEvidence::where('document_id', $document->id)
            ->where('source_id', $sourceId)->firstOrFail();
        $set = DB::table('source_provenance_sets')->where('document_id', $document->id)
            ->where('active', true)->firstOrFail();

        // Offset-free anchors on purpose: they pass the document-match check,
        // so the only thing this test can trip is the role ceiling itself.
        $bound = DB::table('evidence_role_bindings')->where('set_id', $set->id)
            ->where('evidence_id', $evidence->id)->count();
        $roles = ['entity', 'subject', 'metric', 'currency', 'unit', 'scale',
            'qualifier', 'direction', 'numerator', 'denominator', 'period', 'value'];
        for ($i = 0; $bound + $i <= 12; $i++) {
            $anchorId = (string) Str::uuid();
            DB::table('source_anchors')->insert(['id' => $anchorId, 'set_id' => $set->id,
                'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'source_key' => 'ceiling:'.$i, 'kind' => 'paragraph', 'modality' => 'xlsx',
                'created_at' => now(), 'updated_at' => now()]);
            DB::table('evidence_role_bindings')->insert(['set_id' => $set->id,
                'workspace_id' => $document->workspace_id, 'document_id' => $document->id,
                'evidence_id' => $evidence->id, 'anchor_id' => $anchorId,
                'role' => $roles[$i % count($roles)]]);
        }
        self::assertGreaterThan(12, DB::table('evidence_role_bindings')->where('set_id', $set->id)
            ->where('evidence_id', $evidence->id)->count());

        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertStatus(422)
            ->assertExactJson(['error' => ['code' => 'SOURCE_TOO_COMPLEX']]);
    }

    /**
     * A stored anchor whose text no longer hashes to the document it points
     * into is not re-pointed, re-searched or shown with a caveat: the source
     * is reported unavailable, because nothing left on the record proves
     * where in this document the passage is.
     */
    public function test_anchor_that_no_longer_matches_the_document_is_reported_unavailable(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        $set = DB::table('source_provenance_sets')->where('document_id', $document->id)
            ->where('active', true)->firstOrFail();
        $anchor = DB::table('source_anchors')->where('set_id', $set->id)
            ->whereNotNull('start_offset')->orderBy('id')->firstOrFail();
        DB::table('source_anchors')->where('id', $anchor->id)
            ->update(['source_hash' => hash('sha256', 'text that is not in this document')]);

        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertStatus(409)
            ->assertExactJson(['error' => ['code' => 'SOURCE_UNAVAILABLE']]);
    }

    public function test_unbound_header_hash_mismatch_invalidates_the_pinned_source_graph(): void
    {
        [$user, $document, $sourceId] = $this->fixture();
        $set = DB::table('source_provenance_sets')->where('document_id', $document->id)
            ->where('active', true)->firstOrFail();
        $header = DB::table('source_anchors')->where('set_id', $set->id)
            ->where('structural_path', 'sheet:0!A1')->firstOrFail();
        self::assertFalse(DB::table('evidence_role_bindings')->where('anchor_id', $header->id)->exists());
        DB::table('source_anchors')->where('id', $header->id)
            ->update(['source_hash' => hash('sha256', 'text that is not in this document')]);

        Sanctum::actingAs($user);
        $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
            ->assertStatus(409)
            ->assertExactJson(['error' => ['code' => 'SOURCE_UNAVAILABLE']]);
    }

    public function test_valid_source_hashes_remain_resolvable_across_supported_file_types(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        Sanctum::actingAs($user);

        foreach (['PDF', 'DOCX', 'XLSX', 'JPG', 'PNG'] as $type) {
            $document = Document::create(['workspace_id' => $user->current_workspace_id,
                'uploaded_by' => $user->id, 'name' => 'Source.'.$type, 'type' => $type,
                'status' => 'Ready', 'classification' => 'Internal', 'size_kb' => 1,
                'year' => 2025, 'file_hash' => hash('sha256', $type)]);
            $document->forceFill(['extracted_text' => 'Value 12.4'])->save();
            $sourceId = 'kpi:'.Str::uuid();
            DocumentEvidence::create(['workspace_id' => $document->workspace_id,
                'document_id' => $document->id, 'pipeline_key' => 'legacy',
                'identity' => hash('sha256', $type.' value'), 'kind' => 'metric', 'source_id' => $sourceId,
                'data' => ['value' => '12.4'],
                'sources' => [['quote' => '12.4', 'start_offset' => 6, 'end_offset' => 10,
                    'span_id' => 'fixture']]]);
            app(TextRoleProvenanceProducer::class)->publish($document);

            $this->getJson('/api/documents/'.$document->id.'/sources/'.$sourceId)
                ->assertOk()->assertJsonPath('data.matchState', 'EXACT')
                ->assertJsonPath('data.sourceFormat', in_array($type, ['JPG', 'PNG'], true) ? 'OTHER' : $type);
        }
    }
}
