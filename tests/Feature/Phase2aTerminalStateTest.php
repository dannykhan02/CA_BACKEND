<?php

namespace Tests\Feature;

use App\Jobs\ExtractDocumentTextJob;
use App\Jobs\OcrPageBatchJob;
use App\Jobs\ScanUploadedFileJob;
use App\Jobs\SynthesizeBriefNarrativeJob;
use App\Models\Document;
use App\Models\DocumentAiRun;
use App\Models\User;
use App\Services\AnthropicClient;
use App\Services\Pipeline\DocumentStagePolicy;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class Phase2aTerminalStateTest extends TestCase
{
    use RefreshDatabase;

    private function document(string $status): Document
    {
        $user = User::factory()->create();
        app(WorkspaceService::class)->createPersonalWorkspaceFor($user);

        return Document::create(['workspace_id' => $user->current_workspace_id,
            'uploaded_by' => $user->id, 'name' => 'Fixture.pdf', 'type' => 'PDF',
            'status' => $status, 'classification' => 'Internal', 'size_kb' => 1,
            'year' => 2026,
            'extracted_text' => 'Fixture text.', 'file_hash' => hash('sha256', $user->id)]);
    }

    public function test_terminal_document_blocks_late_scan_extract_ocr_b2_and_paid_call(): void
    {
        Bus::fake();
        Http::fake();
        foreach (['Failed', 'Needs Review'] as $status) {
            $document = $this->document($status);
            $policy = app(DocumentStagePolicy::class);
            foreach (['scan', 'extract', 'ocr', 'intelligence', 'brief_synthesis', 'visual'] as $stage) {
                $this->assertFalse($policy->permits($document, $stage));
            }
            app()->call([(new ScanUploadedFileJob($document->id)), 'handle']);
            app()->call([(new ExtractDocumentTextJob($document->id)), 'handle']);
            app()->call([(new OcrPageBatchJob($document->id, [], 1, false)), 'handle']);
            app()->call([(new SynthesizeBriefNarrativeJob($document->id)), 'handle']);
            try {
                $client = app(AnthropicClient::class);
                (new \ReflectionProperty($client, 'currentOperation'))->setValue($client, 'entities');
                (new \ReflectionMethod($client, 'callWithRetry'))->invoke($client,
                    [['role' => 'user', 'content' => 'Fixture text.']], 1,
                    ['intelligence_document' => $document]);
                $this->fail('Terminal document must not call a provider.');
            } catch (\RuntimeException $e) {
                $this->assertSame('Document processing no longer permits intelligence.', $e->getMessage());
            }
            $this->assertSame($status, $document->fresh()->status);
        }
        Http::assertNothingSent();
        Bus::assertNothingDispatched();
        $this->assertSame(0, DocumentAiRun::count());
    }

    public function test_ready_document_only_allows_post_processing_stages(): void
    {
        $document = $this->document('Ready');
        $policy = app(DocumentStagePolicy::class);
        foreach (['scan', 'extract', 'ocr'] as $stage) {
            $this->assertFalse($policy->permits($document, $stage));
        }
        $this->assertTrue($policy->permits($document, 'brief_synthesis'));
        $this->assertTrue($policy->permits($document, 'visual'));
    }
}
