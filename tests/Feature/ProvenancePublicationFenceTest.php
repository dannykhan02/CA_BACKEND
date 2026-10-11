<?php

namespace Tests\Feature;

use App\Models\Document;
use App\Models\User;
use App\Services\Intelligence\SourceProvenance;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProvenancePublicationFenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stale_text_and_deleted_document_cannot_reactivate_provenance(): void
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $document = Document::create(['workspace_id' => $user->fresh()->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Revision.pdf', 'type' => 'PDF',
            'status' => 'Processing', 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2025, 'file_hash' => hash('sha256', 'fixture'), 'extracted_text' => 'First text']);
        $store = app(SourceProvenance::class);
        $anchors = [['key' => 'raw:0:5', 'kind' => 'text_span', 'modality' => 'embedded_text',
            'start_offset' => 0, 'end_offset' => 5, 'text' => 'First']];
        $first = $store->publish($document, 'raw-text.v1', $anchors, [], []);
        self::assertSame($first, $store->publish($document, 'raw-text.v1', $anchors, [], []));
        $document->fresh()->forceFill(['extracted_text' => 'Second text'])->save();
        try {
            $store->publish($document, 'raw-text.v1', $anchors, [], []);
            self::fail('Stale text reactivated provenance.');
        } catch (\LogicException $e) {
            self::assertStringContainsString('Stale provenance publisher', $e->getMessage());
        }
        $document->fresh()->delete();
        $this->expectException(\LogicException::class);
        $store->publish($document, 'raw-text.v1', $anchors, [], []);
    }
}
