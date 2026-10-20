<?php

namespace App\Jobs;

use App\Exceptions\MalwareScannerUnavailableException;
use App\Models\Document;
use App\Services\Documents\UploadDispatchRecovery;
use App\Services\Intelligence\DocumentRevisionPublisher;
use App\Services\Pipeline\DocumentProgress;
use App\Services\Pipeline\DocumentStagePolicy;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ScanUploadedFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 60;

    public function __construct(public string $documentId, public ?string $revisionId = null) {}

    public function handle(PipelineStageRecorder $recorder): void
    {
        $document = Document::find($this->documentId);
        if (! $document) {
            Log::warning("ScanUploadedFileJob: Document {$this->documentId} not found — unexpected null, possible soft-delete race.");

            return;
        }
        if (! app(DocumentStagePolicy::class)->permits($document, 'scan')
            || ! app(DocumentRevisionPublisher::class)->owns($document, $this->revisionId)) {
            return;
        }

        app(UploadDispatchRecovery::class)->claim($document, $this->revisionId);
        $document->refresh();
        if (! app(DocumentStagePolicy::class)->permits($document, 'scan')
            || ! app(DocumentRevisionPublisher::class)->owns($document, $this->revisionId)) {
            return;
        }

        if (! config('document_processing.clamav_enabled')) {
            Log::warning("ClamAV disabled — skipping malware scan for document {$document->id}");
            $recorder->skip($document, 'virus_scan', 'ClamAV disabled via config');

            return;
        }

        $scanStage = $recorder->start($document, 'virus_scan');
        app(DocumentProgress::class)->record($document->id, 'scanning', 5);

        $tmpPath = tempnam(sys_get_temp_dir(), 'clamscan_');

        try {
            if (config('document_processing.clamav_driver') !== 'cli') {
                throw new MalwareScannerUnavailableException('Only the ClamAV CLI driver is supported.');
            }
            // Remote storage is copied locally; cleanup also covers read failures.
            file_put_contents($tmpPath, Storage::disk('documents')->get($document->file_path));
            $result = $this->scanWithClamscan($tmpPath);
        } catch (MalwareScannerUnavailableException $e) {
            $recorder->fail($scanStage, 'SCANNER_UNAVAILABLE: '.$e->getMessage());
            throw $e;
        } finally {
            @unlink($tmpPath);
        }

        if ($result === 'FOUND') {
            $recorder->fail($scanStage, 'MALWARE_FOUND: File failed malware scan.');
            $this->markScanFailed('File failed malware scan and was not processed.', deleteSource: true);
            $this->fail(new \RuntimeException('Malware detected in uploaded file.'));

            return;
        }

        $recorder->complete($scanStage, ['result' => 'clean']);
    }

    private function scanWithClamscan(string $path): string
    {
        if (! file_exists($path) || ! is_readable($path)) {
            throw new \RuntimeException("File not found or unreadable for scanning: {$path}");
        }

        $binary = config('document_processing.clamav_binary', 'clamscan');

        try {
            $result = Process::timeout(45)->run([$binary, '--no-summary', $path]);
        } catch (\Throwable $e) {
            Log::error("clamscan execution failed for {$path}: ".$e->getMessage());
            throw new MalwareScannerUnavailableException('Malware scanner unavailable.');
        }

        if (! in_array($result->exitCode(), [0, 1], true)) {
            Log::error("clamscan error for {$path}: ".$result->errorOutput());
            throw new MalwareScannerUnavailableException('Malware scanner unavailable: '.trim($result->errorOutput()));
        }

        return $result->exitCode() === 1 ? 'FOUND' : 'OK';
    }

    public function failed(\Throwable $e): void
    {
        $document = Document::find($this->documentId);
        if ($e instanceof MalwareScannerUnavailableException) {
            Log::critical('Malware scanner was unreachable for the full retry budget — uploads are effectively blocked.', [
                'document_id' => $this->documentId,
            ]);
        }
        $this->markScanFailed('File scan could not be completed. Please try again.');
    }

    private function markScanFailed(string $message, bool $deleteSource = false): bool
    {
        return DB::transaction(function () use ($message, $deleteSource): bool {
            $locked = Document::whereKey($this->documentId)->lockForUpdate()->first();
            if (! $locked || in_array($locked->status, ['Failed', 'Needs Review'], true)
                || ! app(DocumentRevisionPublisher::class)->owns($locked, $this->revisionId)) {
                return false;
            }
            $locked->forceFill(['status' => 'Failed', 'error_message' => $message])->save();
            if ($this->revisionId !== null) {
                app(DocumentRevisionPublisher::class)->fail($locked, $this->revisionId, 'scan_failed');
            }
            if ($deleteSource) {
                Storage::disk('documents')->delete($locked->file_path);
            }

            return true;
        });
    }
}
