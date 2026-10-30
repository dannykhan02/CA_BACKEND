<?php

namespace Tests\Feature;

use App\Models\ComparisonIdentityLink;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Services\DocumentComparisonService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsIdentityPair;
use Tests\TestCase;

class ComparisonM2B1CorrectionTest extends TestCase
{
    use BuildsIdentityPair;
    use RefreshDatabase;

    public function test_rejected_and_revoked_bindings_can_be_explicitly_reopened_without_confirming_or_rewriting_history(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $path = '/api/document-comparisons/'.$comparison->id.'/identity';
        $input = ['key' => $comparison->metadata['identity_context']['candidates'][0]['key'], 'version' => 0,
            'state' => 'candidate', 'reason' => 'Reconsider after inspecting both originals.'];
        $this->patchJson($path, $input)->assertStatus(409);
        $this->patchJson($path, [...$input, 'state' => 'rejected'])->assertOk();
        $historical = app(DocumentComparisonService::class)->create($user, $a->id, $b->id);
        $pinned = $historical->metadata;
        $this->patchJson($path, [...$input, 'version' => 1, 'reason' => '  '])->assertUnprocessable();
        $this->patchJson($path, [...$input, 'version' => 1])->assertOk()->assertJsonPath('data.version', 2);
        $this->patchJson($path, [...$input, 'version' => 1])->assertStatus(409);
        $next = app(DocumentComparisonService::class)->create($user, $a->id, $b->id);
        $this->assertSame('candidate', $next->metadata['identity_context']['candidates'][0]['state']);
        $this->assertEmpty($next->metadata['identity_usage']);
        $this->assertSame($pinned, $historical->fresh()->metadata);
        $this->patchJson($path, [...$input, 'version' => 2, 'state' => 'confirmed'])->assertOk();
        $this->patchJson($path, [...$input, 'version' => 3, 'state' => 'revoked'])->assertOk();
        $this->patchJson($path, [...$input, 'version' => 4])->assertOk()->assertJsonPath('data.state', 'candidate');
        $this->assertSame(['rejected', 'candidate', 'confirmed', 'revoked', 'candidate'], DB::table('comparison_identity_events')->orderBy('id')->pluck('state')->all());
        $this->assertSame([1, 2, 3, 4, 5], DB::table('comparison_identity_events')->orderBy('id')->pluck('version')->all());
        $this->assertSame(1, ComparisonIdentityLink::count());
    }

    public function test_reconsideration_requires_current_evidence_revision_and_access(): void
    {
        [$user, $a, $b, $comparison] = $this->identityPair();
        $path = '/api/document-comparisons/'.$comparison->id.'/identity';
        $input = ['key' => $comparison->metadata['identity_context']['candidates'][0]['key'], 'version' => 0,
            'state' => 'rejected', 'reason' => 'Original review.'];
        $this->patchJson($path, $input)->assertOk();
        DocumentEvidence::where('document_id', $b->id)->delete();
        $this->patchJson($path, [...$input, 'version' => 1, 'state' => 'candidate'])->assertStatus(409);
        $revision = DocumentIntelligenceRevision::create(['workspace_id' => $a->workspace_id, 'document_id' => $a->id,
            'generation' => 2, 'state' => 'published', 'extracted_text' => 'New', 'extracted_text_hash' => hash('sha256', 'New')]);
        $a->forceFill(['published_intelligence_revision_id' => $revision->id, 'intelligence_generation' => 2])->save();
        $this->patchJson($path, [...$input, 'version' => 1, 'state' => 'candidate'])->assertStatus(409);
        $user->currentWorkspace->update(['type' => 'Organization']);
        $user->update(['role' => 'Viewer']);
        $this->patchJson($path, [...$input, 'version' => 1, 'state' => 'candidate'])->assertForbidden();
        $this->assertSame(1, DB::table('comparison_identity_events')->count());
    }
}
