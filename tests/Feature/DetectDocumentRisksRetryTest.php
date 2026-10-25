<?php

namespace Tests\Feature;

use App\Exceptions\AiProcessingException;
use App\Exceptions\AnthropicStructuredOutputException;
use App\Jobs\DetectDocumentRisksJob;
use App\Models\Document;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Mockery;
use Tests\TestCase;

class DetectDocumentRisksRetryTest extends TestCase
{
    use RefreshDatabase;

    private function document(): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        Sanctum::actingAs($user);

        $document = Document::create([
            'workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'name' => 'Report.pdf', 'type' => 'PDF', 'status' => 'Ready', 'progress' => 100,
            'classification' => 'Internal', 'size_kb' => 10, 'year' => 2026,
            'extracted_text' => 'The report identifies a material risk.',
        ]);
        $document->forceFill(['credit_accounted_at' => now()])->save();

        return $document;
    }

    private function queuedJob(Document $document, int $attempt, bool $expectsFail = false): DetectDocumentRisksJob
    {
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('uuid')->andReturn('risk-retry-uuid');
        $queueJob->shouldReceive('attempts')->andReturn($attempt);
        $queueJob->shouldReceive('fail')->times($expectsFail ? 1 : 0);
        $job = new DetectDocumentRisksJob($document->id, true);
        $job->setJob($queueJob);

        return $job;
    }

    private function malformed(): AnthropicStructuredOutputException
    {
        return new AnthropicStructuredOutputException('malformed_output', 'Invalid control character.');
    }

    public function test_malformed_response_can_complete_on_the_existing_queue_retry(): void
    {
        $document = $this->document();
        $client = $this->mock(AnthropicClient::class);
        $valid = [
            'risks' => [['title' => 'Funding risk', 'description' => 'Funding may fall short.',
                'severity' => 'high', 'confidence' => 0.9, 'evidence' => 'material risk']],
            'prompt_version' => 1,
        ];
        $calls = 0;
        $client->shouldReceive('detectDocumentRisks')->twice()->andReturnUsing(function () use (&$calls, $valid) {
            if ($calls++ === 0) {
                throw $this->malformed();
            }

            return $valid;
        });
        $recorder = app(PipelineStageRecorder::class);

        try {
            $this->queuedJob($document, 1)->handle($client, $recorder);
            $this->fail('Malformed output should reach the queue worker for retry.');
        } catch (AnthropicStructuredOutputException $e) {
            $this->assertSame('malformed_output', $e->outputStatus);
        }
        $first = $document->processingJobs()->where('stage', 'risks')->sole();
        $this->assertSame('processing', $first->status);
        $this->assertNull($first->completed_at);
        $this->assertSame('processing', $this->getJson("/api/documents/{$document->id}/intelligence")
            ->assertOk()->json('data.processing.risks'));

        $this->travel(1)->seconds();
        $this->queuedJob($document, 2)->handle($client, $recorder);

        $this->assertSame('skipped', $first->fresh()->status);
        $this->assertSame(['skipped', 'completed'], $document->processingJobs()->where('stage', 'risks')
            ->orderBy('created_at')->orderBy('id')->pluck('status')->all());
        $this->assertSame(1, $document->risks()->count());
        $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk()
            ->assertJsonPath('data.processing.risks', 'completed')
            ->assertJsonPath('data.summary.risks', 1);
        $this->assertSame('Ready', $document->fresh()->status);
        $this->assertNull($document->fresh()->error_message);
    }

    public function test_malformed_response_exhaustion_fails_only_the_last_attempt(): void
    {
        $document = $this->document();
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('detectDocumentRisks')->twice()->andThrow($this->malformed());
        $recorder = app(PipelineStageRecorder::class);

        foreach ([1, 2] as $attempt) {
            if ($attempt === 2) {
                $this->travel(1)->seconds();
            }
            try {
                $this->queuedJob($document, $attempt)->handle($client, $recorder);
                $this->fail('Malformed output should reach the queue worker.');
            } catch (AnthropicStructuredOutputException $e) {
                $this->assertSame('malformed_output', $e->outputStatus);
            }
            $this->assertSame('processing', $document->processingJobs()->where('stage', 'risks')
                ->orderByDesc('created_at')->orderByDesc('id')->firstOrFail()->status);
        }

        // Laravel invokes failed() on a restored job after its second delivery is exhausted.
        $this->queuedJob($document, 2)->failed($this->malformed());
        $last = $document->processingJobs()->where('stage', 'risks')
            ->orderByDesc('created_at')->orderByDesc('id')->firstOrFail();
        $completedAt = $last->completed_at;
        $this->assertNotNull($completedAt);
        $this->queuedJob($document, 2)->failed($this->malformed());

        $this->assertSame('failed', $last->fresh()->status);
        $this->assertEquals($completedAt, $last->fresh()->completed_at);
        $this->assertSame(1, $document->processingJobs()->where('stage', 'risks')->where('status', 'failed')->count());
        $this->assertSame(2, $document->processingJobs()->where('stage', 'risks')->count());
        $this->assertSame(0, $document->risks()->count());
        $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk()
            ->assertJsonPath('data.processing.risks', 'failed')
            ->assertJsonPath('data.summary.risks', 0);
    }

    public function test_terminal_provider_error_fails_immediately(): void
    {
        $document = $this->document();
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('detectDocumentRisks')->once()->andThrow(new AiProcessingException('authentication'));

        $this->queuedJob($document, 1, true)->handle($client, app(PipelineStageRecorder::class));

        $this->assertSame('failed', $document->processingJobs()->where('stage', 'risks')->sole()->status);
        $this->assertSame(0, $document->risks()->count());
        $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk()
            ->assertJsonPath('data.processing.risks', 'failed');
    }

    public function test_valid_empty_risks_is_completed_and_distinct_from_failure(): void
    {
        $document = $this->document();
        $client = $this->mock(AnthropicClient::class);
        $client->shouldReceive('detectDocumentRisks')->once()->andReturn(['risks' => [], 'prompt_version' => 1]);

        $this->queuedJob($document, 1)->handle($client, app(PipelineStageRecorder::class));

        $this->assertSame('completed', $document->processingJobs()->where('stage', 'risks')->sole()->status);
        $this->assertSame(0, $document->risks()->count());
        $this->getJson("/api/documents/{$document->id}/intelligence")->assertOk()
            ->assertJsonPath('data.processing.risks', 'completed')
            ->assertJsonPath('data.summary.risks', 0)
            ->assertJsonPath('data.risks', []);
    }
}
