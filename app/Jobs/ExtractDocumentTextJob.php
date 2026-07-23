<?php

namespace App\Jobs;

use App\Models\Document;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

class ExtractDocumentTextJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public string $documentId) {}

    public function handle(): void
    {
        $document = Document::find($this->documentId);
        if (! $document || $document->status === 'Failed') {
            return;
        }

        $document->forceFill(['progress' => 25])->save();

        $absolutePath = Storage::disk('documents')->path($document->file_path);

        try {
            $text = $document->type === 'PDF'
                ? $this->extractPdfText($absolutePath)
                : $this->extractDocxText($absolutePath);
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

    private function extractPdfText(string $path): string
    {
        $parser = new PdfParser();
        return $parser->parseFile($path)->getText();
    }

    private function extractDocxText(string $path): string
    {
        $phpWord = IOFactory::load($path);
        $text = '';
        foreach ($phpWord->getSections() as $section) {
            foreach ($section->getElements() as $element) {
                if (method_exists($element, 'getText')) {
                    $text .= $element->getText() . "\n";
                }
            }
        }
        return $text;
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
