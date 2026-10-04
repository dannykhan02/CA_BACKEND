<?php

namespace Tests\Feature;

use App\Jobs\GenerateDocumentSummaryJob;
use App\Models\Document;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Database\Seeders\DocumentSummaryPromptSeederV3;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_completed_haiku_response_with_overlong_assessment_is_invalid_schema_without_retry(): void
    {
        $document = $this->document();
        Http::fake(['api.anthropic.com/*' => Http::response(
            $this->response($this->summary($document, str_repeat('a', 351)))
        )]);

        (new GenerateDocumentSummaryJob($document->id, true))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));

        Http::assertSentCount(1);
        $this->assertDatabaseHas('document_ai_runs', [
            'document_id' => $document->id, 'purpose' => 'document_summary',
            'status' => 'invalid_schema', 'stop_reason' => 'end_turn', 'prompt_version' => '3',
        ]);
        $this->assertSame('failed', $document->processingJobs()->where('stage', 'document_summary')->sole()->status);
        $this->assertStringContainsString('executive_assessment.text: exceeds 350 characters (received 351)',
            $document->processingJobs()->where('stage', 'document_summary')->sole()->error_message);
        $this->assertDatabaseCount('document_intelligence_summaries', 0);
        $this->assertDatabaseCount('billing_operations', 0);
    }
}
