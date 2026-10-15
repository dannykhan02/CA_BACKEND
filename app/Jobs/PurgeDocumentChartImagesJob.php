<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChart;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

/** Remove every retained revision image after a document is permanently deleted. */
class PurgeDocumentChartImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public bool $failOnTimeout = true;

    /** Give transient object-store failures time to recover before retrying. */
    public array $backoff = [60, 300];

    public function __construct(public string $workspaceId, public string $documentId) {}

    public function handle(): void
    {
        if (! Str::isUuid($this->workspaceId) || ! Str::isUuid($this->documentId)) {
            throw new RuntimeException('Invalid chart-image purge ownership identifiers.');
        }
        // A restored/recreated row must never lose its images to a late retry.
        if (Document::withTrashed()->whereKey($this->documentId)->exists()) {
            return;
        }

        $path = 'chart-visuals/'.$this->workspaceId.'/'.$this->documentId;
        // This also protects a historical revision chart or another retained
        // chart row that still references an object under this prefix.
        if (DocumentChart::where('source_metadata->path', 'like', $path.'/%')->exists()) {
            throw new RuntimeException('Retained chart metadata still references the purge prefix.');
        }
        if (! Storage::disk('documents')->deleteDirectory($path)) {
            throw new RuntimeException('Unable to purge retained document chart images.');
        }
    }

    public function failed(\Throwable $error): void
    {
        Log::critical('Chart-image purge exhausted retries; operator review required.', [
            'workspace_id' => $this->workspaceId,
            'document_id' => $this->documentId,
            'error_type' => $error::class,
        ]);
    }
}
