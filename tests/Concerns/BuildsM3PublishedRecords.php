<?php

namespace Tests\Concerns;

use App\Models\Document;
use App\Models\DocumentDeadline;
use App\Models\DocumentEntity;
use App\Models\DocumentEvidence;
use App\Models\DocumentIntelligenceRevision;
use App\Models\DocumentRisk;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Support\Str;

trait BuildsM3PublishedRecords
{
    public function documentWithPublishedRecords(?User $existingUser = null, string $days = '45', string $severity = 'high', bool $partial = false): array
    {
        $user = $existingUser ?? User::factory()->create();
        if ($existingUser === null) {
            app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        }
        $user->refresh();
        $this->actingAs($user, 'sanctum');
        $text = "Clause 4. Buyer shall pay within {$days} days of invoice receipt.\nPlant A has supply interruption exposure.\nAcme is the supplier.\nService level: unavailable.";
        $pipeline = ['route' => 'incremental', 'key' => 'm3-published-'.Str::random(8), 'partial' => $partial];
        $doc = Document::create(['workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Authored QA.docx', 'type' => 'DOCX', 'size_kb' => 1, 'status' => 'Ready', 'classification' => 'Public', 'year' => 2026]);
        $revision = DocumentIntelligenceRevision::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id,
            'generation' => 1, 'state' => 'published', 'extracted_text' => $text, 'extracted_text_hash' => hash('sha256', $text), 'ai_pipeline' => $pipeline]);
        $doc->forceFill(['intelligence_generation' => 1, 'published_intelligence_revision_id' => $revision->id])->save();
        $common = ['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id, 'intelligence_revision_id' => $revision->id, 'confidence' => .9, 'prompt_version' => 'authored-m3'];
        $deadline = DocumentDeadline::create($common + ['title' => 'Payment', 'deadline_type' => 'Contractual-Obligation', 'description' => 'Buyer payment', 'date_type' => 'relative', 'relative_text' => 'within '.$days.' days of invoice receipt', 'status' => 'open', 'evidence' => explode("\n", $text)[0]]);
        $risk = DocumentRisk::create($common + ['title' => 'Supply risk', 'risk_type' => 'operational', 'description' => 'Supply interruption exposure', 'severity' => $severity, 'status' => 'open', 'evidence' => explode("\n", $text)[1]]);
        $entity = DocumentEntity::create($common + ['entity_type' => 'organization', 'value' => 'Acme', 'normalized_value' => 'acme', 'context' => explode("\n", $text)[2]]);
        foreach ([['deadline:'.$deadline->id, 'obligation', ['subject' => 'Buyer', 'reference' => 'Clause 4']], ['risk:'.$risk->id, 'risk', ['subject' => 'Plant A', 'severity' => $severity]], ['entity:'.$entity->id, 'entity', ['role' => 'supplier']], ['fact:service', 'metric', ['label' => 'Service level', 'value' => 'unavailable', 'unit' => '%']]] as [$id, $kind, $data]) {
            $quote = match ($kind) {
                'obligation' => $deadline->evidence, 'risk' => $risk->evidence, 'entity' => $entity->context, default => 'Service level: unavailable.'
            };
            $start = mb_strpos($text, $quote);
            DocumentEvidence::create(['workspace_id' => $doc->workspace_id, 'document_id' => $doc->id, 'pipeline_key' => $pipeline['key'],
                'identity' => hash('sha256', $id), 'kind' => $kind, 'source_id' => $id, 'data' => $data + ['quote' => $quote],
                'sources' => [['quote' => $quote, 'start_offset' => $start, 'end_offset' => $start + mb_strlen($quote), 'page' => null]]]);
        }

        return [$doc, $revision, $deadline, $risk, $entity];
    }
}
