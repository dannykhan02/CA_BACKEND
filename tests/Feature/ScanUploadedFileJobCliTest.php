<?php

namespace Tests\Feature;

use App\Enums\WorkspaceType;
use App\Exceptions\MalwareScannerUnavailableException;
use App\Jobs\ScanUploadedFileJob;
use App\Models\Document;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ScanUploadedFileJobCliTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['document_processing.clamav_enabled' => true, 'document_processing.clamav_driver' => 'cli']);
        Storage::fake('documents');
    }

    private function makeDocument(array $overrides = []): Document
    {
        $user = User::factory()->create();
        $workspace = Workspace::create(['type' => WorkspaceType::Organization, 'name' => 'ClamAV CLI Test WS']);

        return Document::create(array_merge([
            'name' => 'clamscan-test.pdf',
            'file_path' => 'clamscan-test-' . uniqid() . '.pdf',
            'type' => 'PDF',
            'size_kb' => 1,
            'status' => 'Processing',
            'classification' => 'Public',
            'year' => 2026,
            'workspace_id' => $workspace->id,
            'uploaded_by' => $user->id,
            'last_updated_by' => $user->id,
        ], $overrides));
    }

    public function test_marks_a_clean_file_as_passed_via_clamscan(): void
    {
        Process::fake(['*' => Process::result(exitCode: 0)]);
        $document = $this->makeDocument();
        Storage::disk('documents')->put($document->file_path, 'harmless content');

        (new ScanUploadedFileJob($document->id))->handle(app(PipelineStageRecorder::class));

        $this->assertNotEquals('Failed', $document->fresh()->status);
    }

    public function test_marks_document_failed_when_clamscan_finds_malware(): void
    {
        Process::fake(['*' => Process::result(exitCode: 1)]);
        $document = $this->makeDocument();
        Storage::disk('documents')->put(
            $document->file_path,
            'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
        );

        (new ScanUploadedFileJob($document->id))->handle(app(PipelineStageRecorder::class));

        $fresh = $document->fresh();
        $this->assertEquals('Failed', $fresh->status);
        $this->assertStringContainsString('malware scan', $fresh->error_message);
    }

    public function test_fails_closed_when_clamscan_errors(): void
    {
        Process::fake(['*' => Process::result(exitCode: 2, errorOutput: "ERROR: Can't open file or directory")]);
        $document = $this->makeDocument();
        Storage::disk('documents')->put($document->file_path, 'irrelevant');

        $this->expectException(MalwareScannerUnavailableException::class);

        (new ScanUploadedFileJob($document->id))->handle(app(PipelineStageRecorder::class));
    }

    public function test_real_clamscan_catches_a_real_eicar_string(): void
    {
        if (! trim(shell_exec('which clamscan') ?? '')) {
            $this->markTestSkipped('clamscan not installed on this machine');
        }

        $document = $this->makeDocument();
        Storage::disk('documents')->put(
            $document->file_path,
            'X5O!P%@AP[4\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*'
        );

        (new ScanUploadedFileJob($document->id))->handle(app(PipelineStageRecorder::class));

        $this->assertEquals('Failed', $document->fresh()->status);
    }
}
