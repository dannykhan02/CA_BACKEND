<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\GenerateInsightsJob;
use App\Jobs\OcrPageBatchJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\User;
use App\Policies\DocumentPolicy;
use App\Services\AnthropicClient;
use App\Services\DocumentTextExtractor;
use App\Services\Ocr\OcrEngineResolver;
use App\Services\Ocr\OcrPageResult;
use App\Services\Ocr\OcrProviderInterface;
use App\Services\Ocr\PdfRasterizer;
use App\Services\Pipeline\PipelineStageRecorder;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PersonalDocumentWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function owner(string $type = 'Personal'): User
    {
        $user = User::factory()->create(['role' => $type === 'Personal' ? 'Viewer' : 'Reviewer']);
        $workspace = app(WorkspaceService::class)->createPersonalWorkspaceFor($user);
        $workspace->update(['type' => WorkspaceType::from($type)]);
        $workspace->credits()->update(['documents_remaining' => 10]);

        return $user->fresh();
    }

    private function document(User $user, string $classification = 'Internal'): Document
    {
        return Document::create([
            'name' => 'Report.pdf', 'type' => 'PDF', 'size_kb' => 1, 'year' => 2026,
            'classification' => $classification, 'status' => 'Processing',
            'workspace_id' => $user->current_workspace_id, 'uploaded_by' => $user->id,
            'file_path' => 'report.pdf', 'extracted_text' => 'Revenue increased.',
        ]);
    }

    public static function classifications(): array
    {
        return array_map(fn ($value) => [$value], ['Public', 'Internal', 'Confidential', 'Restricted']);
    }

    #[DataProvider('classifications')]
    public function test_successful_analysis_goes_directly_to_ready_for_both_workspace_types(string $classification): void
    {
        $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')->twice()->andReturn([]));
        foreach (['Personal', 'Organization'] as $type) {
            $document = $this->document($this->owner($type), $classification);
            (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
            $this->assertSame('Ready', $document->fresh()->status);
            // The observer audits every persisted status transition; a terminal
            // assertion alone would miss a brief detour through Needs Review.
            $this->assertDatabaseMissing('audit_logs', [
                'workspace_id' => $document->workspace_id, 'action' => 'document.needs_review',
            ]);
        }
    }

    #[DataProvider('classifications')]
    public function test_personal_policy_and_listing_use_ownership_for_every_classification(string $classification): void
    {
        $owner = $this->owner();
        $other = $this->owner();
        $document = $this->document($owner, $classification);
        $policy = new DocumentPolicy;
        $this->assertTrue($policy->view($owner, $document));
        $this->assertFalse($policy->view($other, $document));
        // Even sharing current_workspace_id does not bypass uploader isolation.
        $foreignDocument = $this->document($owner, $classification);
        $foreignDocument->update(['uploaded_by' => $other->id]);
        Sanctum::actingAs($owner);
        $this->getJson('/api/documents')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $document->id);
        $this->getJson('/api/documents/'.$document->id)->assertOk();
        $this->getJson('/api/documents/'.$foreignDocument->id)->assertForbidden();
    }

    #[DataProvider('classifications')]
    public function test_unchanged_personal_analysis_also_finishes_directly_as_ready(string $classification): void
    {
        $document = $this->document($this->owner(), $classification);
        $document->update(['file_hash' => hash('sha256', 'unchanged')]);
        DocumentAiRun::create([
            'document_id' => $document->id, 'workspace_id' => $document->workspace_id,
            'purpose' => 'insights', 'provider' => 'anthropic', 'model' => 'test',
            'prompt_version' => 1, 'file_hash' => $document->file_hash, 'created_at' => now(),
        ]);
        $client = $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldNotReceive('extractDocumentInsights'));
        (new GenerateInsightsJob($document->id))->handle($client, app(PipelineStageRecorder::class));
        $this->assertSame('Ready', $document->fresh()->status);
        $this->assertDatabaseMissing('audit_logs', ['workspace_id' => $document->workspace_id, 'action' => 'document.needs_review']);
    }

    public static function omittedClassifications(): array
    {
        return [[[]], [['classification' => null]], [['classification' => '']]];
    }

    #[DataProvider('omittedClassifications')]
    public function test_personal_upload_defaults_classification_and_can_finish_analysis(array $fields): void
    {
        Storage::fake('documents');
        Queue::fake();
        $owner = $this->owner();
        Sanctum::actingAs($owner);
        $this->postJson('/api/documents', $fields + ['file' => UploadedFile::fake()->create('report.pdf', 1, 'application/pdf')])
            ->assertStatus(202);
        $document = Document::where('workspace_id', $owner->current_workspace_id)->sole();
        $this->assertSame('Internal', $document->classification);
        $document->update(['extracted_text' => 'Revenue increased.']);
        $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldReceive('extractDocumentInsights')->once()->andReturn([]));
        (new GenerateInsightsJob($document->id))->handle(app(AnthropicClient::class), app(PipelineStageRecorder::class));
        $this->assertSame('Ready', $document->fresh()->status);
    }

    #[DataProvider('omittedClassifications')]
    public function test_organization_still_requires_classification(array $fields): void
    {
        Sanctum::actingAs($this->owner('Organization'));
        $this->postJson('/api/documents', $fields + ['file' => UploadedFile::fake()->create('report.pdf', 1, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('classification');
    }

    public static function ocrFailures(): array
    {
        $cases = [];
        foreach (['Personal' => 'Failed', 'Organization' => 'Needs Review'] as $type => $status) {
            foreach (['resolver', 'rasterizer', 'batch', 'empty'] as $failure) {
                $cases["$type $failure"] = [$type, $status, $failure];
            }
        }

        return $cases;
    }

    #[DataProvider('ocrFailures')]
    public function test_actual_ocr_review_triggers_are_workspace_specific(string $type, string $status, string $failure): void
    {
        Storage::fake('documents');
        Storage::disk('documents')->put('report.pdf', 'scanned fixture');
        $document = $this->document($this->owner($type));
        $document->update(['extracted_text' => null]);
        $provider = \Mockery::mock(OcrProviderInterface::class);
        $resolver = $this->mock(OcrEngineResolver::class);
        if ($failure === 'resolver') {
            $resolver->shouldReceive('resolve')->once()->andThrow(new \RuntimeException('No provider'));
        } else {
            $resolver->shouldReceive('resolve')->once()->andReturn($provider);
        }

        if (in_array($failure, ['resolver', 'rasterizer'])) {
            $extractor = $this->mock(DocumentTextExtractor::class, function ($mock) {
                $mock->shouldReceive('extractPdfText')->once()->andReturn('');
                $mock->shouldReceive('countPdfPages')->once()->andReturn(1);
            });
            $rasterizer = $this->mock(PdfRasterizer::class);
            if ($failure === 'rasterizer') {
                $rasterizer->shouldReceive('toPageImages')->once()->andThrow(new \RuntimeException('Rasterization failed'));
            }
            (new ExtractDocumentTextJob($document->id))->handle($extractor, app(PipelineStageRecorder::class), $resolver, $rasterizer);
        } else {
            if ($failure === 'batch') {
                $provider->shouldReceive('extractPage')->once()->andThrow(new \RuntimeException('OCR failed'));
            } else {
                $provider->shouldReceive('extractPage')->once()->andReturn(new OcrPageResult('', 0));
                $provider->shouldReceive('engine')->once()->andReturn('vision');
            }
            (new OcrPageBatchJob($document->id, ['page.png'], 1, true))->handle($resolver, app(PipelineStageRecorder::class));
        }

        $this->assertSame($status, $document->fresh()->status);
        $client = $this->mock(AnthropicClient::class, fn ($mock) => $mock->shouldNotReceive('extractDocumentInsights'));
        (new GenerateInsightsJob($document->id))->handle($client, app(PipelineStageRecorder::class));
        $this->assertSame($status, $document->fresh()->status);
        $this->assertSame(10, $document->workspace->credits->documents_remaining);
        if ($type === 'Personal') {
            $this->assertDatabaseMissing('audit_logs', ['workspace_id' => $document->workspace_id, 'action' => 'document.needs_review']);
        }
    }
}
