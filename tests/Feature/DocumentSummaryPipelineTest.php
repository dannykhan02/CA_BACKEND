<?php

namespace Tests\Feature;

use App\Http\Resources\DocumentIntelligenceSummaryResource;
use App\Jobs\GenerateDocumentSummaryJob;
use App\Models\Document;
use App\Models\DocumentIntelligenceSummary;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\DocumentIntelligenceService;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DocumentSummaryPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function document(): Document
    {
        $this->seed(DocumentSummaryPromptSeederV3::class);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

        $document = Document::create([
            'workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Annual report.pdf', 'type' => 'PDF', 'status' => 'Processing',
            'classification' => 'Internal', 'size_kb' => 10, 'year' => 2026,
            'file_hash' => hash('sha256', 'annual report'),
            'extracted_text' => 'The report contains a debt risk.',
        ]);
        // This stage is a retry of an already-accounted document.
        $document->forceFill(['credit_accounted_at' => now()])->save();
        $document->risks()->create([
            'workspace_id' => $document->workspace_id, 'risk_type' => 'financial',
            'title' => 'Debt rose', 'description' => 'Debt rose during the period.',
            'severity' => 'high', 'confidence' => 0.9,
            'evidence' => 'Debt rose during the period.', 'prompt_version' => '1',
            'provider' => 'anthropic', 'model' => 'claude-test',
        ]);

        return $document;
    }

    private function response(array $summary): array
    {
        return [
            'model' => 'claude-haiku-4-5-20251001', 'stop_reason' => 'end_turn',
            'usage' => ['input_tokens' => 6980, 'output_tokens' => 3200],
            'content' => [['type' => 'text', 'text' => json_encode($summary)]],
        ];
    }

    private function summary(Document $document, string $assessmentText): array
    {
        return [
            'executive_summary' => 'Debt rose during the period.',
            'key_findings' => ['Debt rose.'], 'critical_risks' => ['Debt risk.'],
            'upcoming_deadlines' => [], 'important_entities' => [],
            'recommended_attention' => ['Review debt.'],
            'executive_assessment' => [
                'text' => $assessmentText, 'basis' => 'inferred',
                'source_ids' => ['risk:'.$document->risks()->sole()->id],
            ],
            'material_findings' => [], 'trends' => [], 'tensions' => [], 'questions' => [],
        ];
    }

    public function test_completed_haiku_summary_at_contract_boundary_persists_once(): void
    {
        $document = $this->document();
        Http::fake(['api.anthropic.com/*' => Http::response(
            $this->response($this->summary($document, str_repeat('a', 350)))
        )]);

        (new GenerateDocumentSummaryJob($document->id, true))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));

        Http::assertSentCount(1);
        $this->assertSame(str_repeat('a', 350), $document->intelligenceSummary()->sole()->executive_assessment['text']);
        $this->assertSame('completed', $document->processingJobs()->where('stage', 'document_summary')->sole()->status);
        $this->assertDatabaseHas('document_ai_runs', [
            'document_id' => $document->id, 'purpose' => 'document_summary',
            'status' => 'success', 'stop_reason' => 'end_turn', 'prompt_version' => '3',
            'input_tokens' => 6980, 'output_tokens' => 3200,
        ]);
        $this->assertDatabaseCount('billing_operations', 0);
    }

    public function test_completed_haiku_response_with_overlong_assessment_is_normalized_and_saved(): void
    {
        $document = $this->document();
        $assessment = str_repeat('word ', 70).'more';
        Http::fake(['api.anthropic.com/*' => Http::response(
            $this->response($this->summary($document, $assessment))
        )]);

        (new GenerateDocumentSummaryJob($document->id, true))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));

        Http::assertSentCount(1);
        $this->assertDatabaseHas('document_ai_runs', [
            'document_id' => $document->id, 'purpose' => 'document_summary',
            'status' => 'success', 'stop_reason' => 'end_turn', 'prompt_version' => '3',
        ]);
        $this->assertSame('completed', $document->processingJobs()->where('stage', 'document_summary')->sole()->status);
        $this->assertSame(str_repeat('word ', 69).'word', $document->intelligenceSummary()->sole()->executive_assessment['text']);
        $this->assertDatabaseCount('billing_operations', 0);
    }

    public function test_production_shape_371_character_finding_explanation_persists_and_completes_stage(): void
    {
        $document = $this->document();
        $response = $this->summary($document, 'Debt pressure rose.');
        $response['material_findings'] = [[
            'title' => 'Debt increased', 'category' => 'financial',
            'explanation' => str_repeat('word ', 70).'renewed warning here!',
            'why_it_matters' => 'Funding pressure.', 'severity' => 'high',
            'basis' => 'explicit', 'source_ids' => ['risk:'.$document->risks()->sole()->id],
        ]];
        $this->assertSame(371, mb_strlen($response['material_findings'][0]['explanation']));
        Http::fake(['api.anthropic.com/*' => Http::response($this->response($response))]);

        (new GenerateDocumentSummaryJob($document->id, true))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));

        Http::assertSentCount(1);
        $summary = $document->intelligenceSummary()->sole();
        $this->assertSame(str_repeat('word ', 69).'word', $summary->material_findings[0]['explanation']);
        $this->assertSame('completed', $document->processingJobs()->where('stage', 'document_summary')->sole()->status);
        $this->assertDatabaseHas('document_ai_runs', [
            'document_id' => $document->id, 'purpose' => 'document_summary', 'status' => 'success',
        ]);
    }

    public function test_ungrounded_optional_assessment_is_null_while_grounded_summary_persists(): void
    {
        $document = $this->document();
        $response = $this->summary($document, 'Debt pressure rose.');
        $response['executive_assessment']['source_ids'] = [];
        $response['material_findings'] = [[
            'title' => 'Debt increased', 'category' => 'financial',
            'explanation' => 'Debt rose during the period.',
            'why_it_matters' => 'Funding pressure.', 'severity' => 'high',
            'basis' => 'explicit', 'source_ids' => ['risk:'.$document->risks()->sole()->id],
        ]];
        Http::fake(['api.anthropic.com/*' => Http::response($this->response($response))]);

        (new GenerateDocumentSummaryJob($document->id, true))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));

        Http::assertSentCount(1);
        $summary = $document->intelligenceSummary()->sole();
        $this->assertNull($summary->executive_assessment);
        $this->assertSame($response['material_findings'], $summary->material_findings);
        $this->assertNull((new DocumentIntelligenceSummaryResource($summary))->toArray(new Request)['executiveAssessment']);
        $this->assertSame('completed', $document->processingJobs()->where('stage', 'document_summary')->sole()->status);
        $this->assertDatabaseHas('document_ai_runs', [
            'document_id' => $document->id, 'purpose' => 'document_summary', 'status' => 'success',
        ]);
    }

    public function test_successful_ai_response_with_summary_persistence_failure_fails_stage(): void
    {
        $document = $this->document();
        Http::fake(['api.anthropic.com/*' => Http::response(
            $this->response($this->summary($document, 'Debt pressure rose.'))
        )]);
        DocumentIntelligenceSummary::creating(function () {
            throw new \RuntimeException('Simulated summary database failure.');
        });

        try {
            (new GenerateDocumentSummaryJob($document->id, true))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
            $this->fail('Summary persistence failure should escape for queue retry.');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated summary database failure.', $e->getMessage());
        }

        Http::assertSentCount(1);
        $this->assertDatabaseHas('document_ai_runs', [
            'document_id' => $document->id, 'purpose' => 'document_summary', 'status' => 'success',
        ]);
        $this->assertDatabaseCount('document_intelligence_summaries', 0);
        $this->assertSame('failed', $document->processingJobs()->where('stage', 'document_summary')->sole()->status);
        $this->assertSame('failed', app(DocumentIntelligenceService::class)
            ->getProcessingStatus($document)['document_summary']);
    }
}
