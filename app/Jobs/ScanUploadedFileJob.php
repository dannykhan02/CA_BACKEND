<?php

namespace App\Jobs;

use App\Models\Document;
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

    public function handle(): void
    {
        $document = Document::find($this->documentId);
        if (! $document) {
            return;
        }

        if (! config('document_processing.clamav_enabled')) {
            Log::warning("ClamAV disabled — skipping malware scan for document {$document->id}");
            return;
        }

        $absolutePath = Storage::disk('documents')->path($document->file_path);
        $socket = config('document_processing.clamav_socket');

        $result = $this->scanWithClamd($absolutePath, $socket);

        if ($result === 'FOUND') {
            $document->forceFill([
                'status' => 'Failed',
                'error_message' => 'File failed malware scan and was not processed.',
            ])->save();

            Storage::disk('documents')->delete($document->file_path);

            $this->fail(new \RuntimeException('Malware detected in uploaded file.'));
        }
    }

    private function scanWithClamd(string $path, string $socket): string
    {
        $sock = @stream_socket_client("unix://{$socket}", $errno, $errstr, 5);
        if (! $sock) {
            Log::error("Could not connect to clamd at {$socket}: {$errstr}");
            throw new \RuntimeException('Malware scanner unavailable.');
        }

        fwrite($sock, "SCAN {$path}\n");
        $response = fread($sock, 4096);
        fclose($sock);

        return str_contains($response, 'FOUND') ? 'FOUND' : 'OK';
    }

    public function failed(\Throwable $e): void
    {
        $document = Document::find($this->documentId);
        $document?->forceFill([
            'status' => 'Failed',
            'error_message' => $document->error_message ?? 'File scan failed: ' . $e->getMessage(),
        ])->save();
    }
}
