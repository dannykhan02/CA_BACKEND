<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\DocumentTextExtractor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

class ExtractDocumentTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public string $documentId) {}

    public function handle(DocumentTextExtractor $extractor): void
    {
        $document = Document::find($this->documentId);
        if (! $document || $document->status === 'Failed') {
            return;
        }

        $document->forceFill(['progress' => 25])->save();

        $absolutePath = Storage::disk('documents')->path($document->file_path);

        try {
            $text = $document->type === 'PDF'
                ? $extractor->extractPdfText($absolutePath)
                : $extractor->extractDocxText($absolutePath);
        } catch (\Throwable $e) {
            $document->forceFill([
                'status' => 'Failed',
                'error_message' => 'Could not extract text: the file may be corrupted, password-protected, or scanned without a text layer.',
            ])->save();
            $this->fail($e);
            return;
        }

        if (trim($text) === '') {
            $document->forceFill([
                'status' => 'Failed',
                'error_message' => 'No extractable text found — the document may be a scanned image without OCR.',
            ])->save();
            $this->fail(new \RuntimeException('Empty extracted text.'));
            return;
        }

        Cache::put("document:{$document->id}:extracted_text", $text, now()->addHours(2));

        $document->forceFill(['progress' => 50])->save();
    }

    public function failed(\Throwable $e): void
    {
        $document = Document::find($this->documentId);
        $document?->forceFill([
            'status' => 'Failed',
            'error_message' => $document->error_message ?? 'Text extraction failed.',
        ])->save();
    }
}