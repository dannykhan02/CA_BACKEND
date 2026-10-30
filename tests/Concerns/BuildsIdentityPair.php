<?php

namespace Tests\Concerns;

use App\Models\Document;
use App\Models\DocumentDeadline;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\ProcessingJob;
use App\Models\User;
use App\Services\DocumentComparisonService;
use App\Services\WorkspaceService;
use Illuminate\Support\Facades\Bus;

trait BuildsIdentityPair
{
    protected function identityPair(): array
    {
        Bus::fake();
        $user = User::factory()->create(['role' => 'Administrator']);
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->refresh();
        $this->actingAs($user, 'sanctum');
        $docs = [];
        foreach (['45', '30'] as $i => $days) {
            $text = "Clause 4. Buyer shall pay within {$days} days of invoice receipt.";
            $doc = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
                'name' => 'Source '.$i, 'type' => 'PDF', 'size_kb' => 1, 'status' => 'Ready', 'classification' => 'Public', 'year' => 2026]);
            $revision = DocumentIntelligenceRevision::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                'generation' => 1, 'state' => 'published', 'extracted_text' => $text, 'extracted_text_hash' => hash('sha256', $text),
                'ai_pipeline' => ['key' => 'identity-test-'.$i]]);
            $doc->forceFill(['intelligence_generation' => 1, 'published_intelligence_revision_id' => $revision->id])->save();
            $deadline = DocumentDeadline::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                'intelligence_revision_id' => $revision->id, 'title' => $i ? 'Invoice payment' : 'Payment term',
                'description' => 'Buyer payment', 'deadline_type' => 'obligation', 'date_type' => 'relative',
                'relative_text' => "within {$days} days of invoice receipt", 'evidence' => $text, 'status' => 'open', 'confidence' => .9, 'prompt_version' => 'test']);
            DocumentEvidence::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                'pipeline_key' => 'identity-test-'.$i, 'identity' => hash('sha256', $text), 'kind' => 'obligation',
                'source_id' => 'deadline:'.$deadline->id, 'data' => ['subject' => 'Buyer', 'reference' => 'Clause 4'],
                'sources' => [['span_id' => 'E001', 'quote' => $text, 'start_offset' => 0, 'end_offset' => mb_strlen($text), 'page' => 1]]]);
            foreach (['entities', 'risks', 'deadlines'] as $stage) {
                ProcessingJob::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
                    'intelligence_revision_id' => $revision->id, 'stage' => $stage, 'status' => 'completed']);
            }
            $docs[] = $doc;
        }
        $comparison = app(DocumentComparisonService::class)->create($user, $docs[0]->id, $docs[1]->id);

        return [$user, ...$docs, $comparison];
    }
}
