<?php
namespace App\Jobs;

use App\Exceptions\MalwareScannerUnavailableException;
use App\Models\Document;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ScanUploadedFileJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(public string $documentId) {}

    public function handle(PipelineStageRecorder $recorder): void
    {
        $document = Document::find($this->documentId);
        if (! $document) {
            return;
        }

        if (! config('document_processing.clamav_enabled')) {
            Log::warning("ClamAV disabled — skipping malware scan for document {$document->id}");
            $recorder->skip($document, 'virus_scan', 'ClamAV disabled via config');
            return;
        }

        $scanStage = $recorder->start($document, 'virus_scan');

        // The 'documents' disk may be a remote driver (e.g. S3/R2) — clamd
        // scans over a local Unix socket and needs a real filesystem path,
        // so the remote file is pulled to a local temp copy first, scanned,
        // then always cleaned up regardless of outcome. For a local-driver
        // disk this is a redundant but harmless extra copy.
        $tmpPath = tempnam(sys_get_temp_dir(), 'clamscan_');
        file_put_contents($tmpPath, Storage::disk('documents')->get($document->file_path));
        // clamd runs as its own dedicated system user (clamav) and cannot
        // read tempnam()'s default owner-only (0600) permissions — this
        // widens read access to that single temp file only, immediately
        // deleted in the finally block below regardless of scan outcome.
        chmod($tmpPath, 0644);

        $socket = config('document_processing.clamav_socket');

        try {
            $result = $this->scanWithClamd($tmpPath, $socket);
        } catch (MalwareScannerUnavailableException $e) {
            $recorder->fail($scanStage, 'SCANNER_UNAVAILABLE: ' . $e->getMessage());
            throw $e;
        } finally {
            @unlink($tmpPath);
        }

        if ($result === 'FOUND') {
            $recorder->fail($scanStage, 'MALWARE_FOUND: File failed malware scan.');
            $document->forceFill([
                'status' => 'Failed',
                'error_message' => 'File failed malware scan and was not processed.',
            ])->save();
            Storage::disk('documents')->delete($document->file_path);
            $this->fail(new \RuntimeException('Malware detected in uploaded file.'));
            return;
        }

        $recorder->complete($scanStage, ['result' => 'clean']);
    }

    private function scanWithClamd(string $path, string $socket): string
    {
        if (! file_exists($path) || ! is_readable($path)) {
            throw new \RuntimeException("File not found or unreadable for scanning: {$path}");
        }

        $sock = @stream_socket_client("unix://{$socket}", $errno, $errstr, 5);
        if (! $sock) {
            Log::error("Could not connect to clamd at {$socket}: {$errstr}");
            throw new MalwareScannerUnavailableException('Malware scanner unavailable.');
        }
        fwrite($sock, "SCAN {$path}\n");
        $response = fread($sock, 4096);
        fclose($sock);

        if (str_contains($response, 'ERROR')) {
            Log::error("Clamd scan error for {$path}: {$response}");
            throw new \RuntimeException("Clamd scan error: {$response}");
        }

        return str_contains($response, 'FOUND') ? 'FOUND' : 'OK';
    }

    public function failed(\Throwable $e): void
    {
        $document = Document::find($this->documentId);
        if ($e instanceof MalwareScannerUnavailableException) {
            Log::critical('Malware scanner was unreachable for the full retry budget — uploads are effectively blocked.', [
                'document_id' => $this->documentId,
            ]);
        }
        $document?->forceFill([
            'status' => 'Failed',
            'error_message' => $document->error_message ?? 'File scan failed: ' . $e->getMessage(),
        ])->save();
    }
}
