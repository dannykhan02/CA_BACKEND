<?php

namespace Tests\Feature;

use App\Jobs\ExtractDocumentEntitiesJob;
use App\Jobs\GenerateInsightsJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AnthropicStructuredPipelineTest extends TestCase
{
    use RefreshDatabase;

    private function document(): Document
    {
        $this->seed(\Database\Seeders\DocumentEntitiesPromptSeeder::class);
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $user->fresh()->currentWorkspace->credits()->update(['documents_remaining' => 5]);

        return Document::create([
            'workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Annual report.pdf', 'type' => 'PDF', 'status' => 'Processing',
            'classification' => 'Internal', 'size_kb' => 10, 'year' => 2026,
            'file_hash' => hash('sha256', 'annual report'),
            'extracted_text' => 'Acme Ltd is named in this report.',
        ]);
    }

    private function response(string $text, string $stop = 'end_turn'): array
    {
        return ['model' => 'claude-test', 'stop_reason' => $stop,
            'usage' => ['input_tokens' => 500, 'output_tokens' => 40],
            'content' => [['type' => 'text', 'text' => $text]]];
    }

    public function test_malformed_then_valid_response_audits_both_and_persists_once(): void
    {
        $document = $this->document();
        $valid = json_encode(['entities' => [['entity_type' => 'organization', 'value' => 'Acme Ltd',
            'normalized_value' => 'Acme Ltd', 'confidence' => 0.9, 'context' => 'Acme Ltd is named']]]);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->response('{"entities":'))
            ->push($this->response($valid))]);

        (new ExtractDocumentEntitiesJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        (new ExtractDocumentEntitiesJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));

        $this->assertSame(1, $document->entities()->count());
        Http::assertSentCount(2);
        $this->assertSame(['malformed_output', 'success'], DocumentAiRun::where('document_id', $document->id)
            ->where('purpose', 'entities')->pluck('status')->sort()->values()->all());
        $this->assertDatabaseHas('document_ai_runs', ['document_id' => $document->id,
            'purpose' => 'entities', 'status' => 'success', 'model' => 'claude-test',
            'input_tokens' => 500, 'output_tokens' => 40, 'stop_reason' => 'end_turn']);
        $this->assertSame(1, $document->processingJobs()->where('stage', 'entities')->where('status', 'completed')->count());
        $this->assertSame(1, DB::table('billing_operations')->where('resource_id', $document->id)->count());
    }

    public function test_two_malformed_responses_keep_previous_entities_and_never_mark_success(): void
    {
        $document = $this->document();
        $document->entities()->create(['workspace_id' => $document->workspace_id,
            'entity_type' => 'organization', 'value' => 'Old value',
            'normalized_value' => 'Old value', 'confidence' => 0.8,
            'prompt_version' => '1', 'provider' => 'anthropic', 'model' => 'old']);
        Http::fake(['api.anthropic.com/*' => Http::sequence()
            ->push($this->response('{"entities":'))
            ->push($this->response('{"entities":'))]);

        (new ExtractDocumentEntitiesJob($document->id, true))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));

        Http::assertSentCount(2);
        $this->assertSame(['Old value'], $document->entities()->pluck('value')->all());
        $this->assertSame(2, DocumentAiRun::where('document_id', $document->id)->where('purpose', 'entities')->where('status', 'malformed_output')->count());
        $this->assertSame(0, DocumentAiRun::where('document_id', $document->id)->where('purpose', 'entities')->where('status', 'success')->count());
        $this->assertSame(1, DB::table('billing_operations')->where('resource_id', $document->id)->count());
    }

    public function test_insights_failure_after_core_text_keeps_completed_intelligence_visible(): void
    {
        $document = $this->document();
        $document->entities()->create(['workspace_id' => $document->workspace_id,
            'entity_type' => 'organization', 'value' => 'Acme Ltd',
            'normalized_value' => 'Acme Ltd', 'confidence' => 0.9,
            'prompt_version' => '1', 'provider' => 'anthropic', 'model' => 'old']);
        $recorder = app(PipelineStageRecorder::class);
        $recorder->complete($recorder->start($document, 'extract'));
        $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')
            ->once()->andThrow(new \RuntimeException('Malformed output')));

        (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class), $recorder);

        $this->assertSame('Needs Review', $document->fresh()->status);
        $this->assertSame(['Acme Ltd'], $document->entities()->pluck('value')->all());
        $this->assertSame('completed', $document->processingJobs()->where('stage', 'extract')->sole()->status);
        $this->assertSame('failed', $document->processingJobs()->where('stage', 'ai_analysis')->sole()->status);
    }
}
