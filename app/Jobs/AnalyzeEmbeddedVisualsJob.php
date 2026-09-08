<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentChartPoint;
use App\Services\AnthropicClient;
use App\Services\Ocr\PdfRasterizer;
use App\Services\Vision\DocxImageDetector;
use App\Services\Vision\PdfImageDetector;
use App\Services\Vision\VisualReference;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Runs AFTER GenerateInsightsJob, not instead of it — merges vision-derived
 * charts alongside whatever text-based KPIs/charts already exist, rather
 * than racing them. Only appended to the chain when a type-specific
 * detector actually finds something worth looking at (see
 * ExtractDocumentTextJob), so this is a no-op cost for the common
 * text-only document.
 */
class AnalyzeEmbeddedVisualsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public string $documentId) {}

    public function handle(
        AnthropicClient $client,
        PdfImageDetector $pdfDetector,
        DocxImageDetector $docxDetector,
        PdfRasterizer $rasterizer,
    ): void {
        $document = Document::find($this->documentId);
        if (! $document || $document->status !== 'Ready') {
            return; // GenerateInsightsJob must have finished successfully first
        }

        $workspaceVisionEnabled = $document->workspace?->aiConfig?->vision_enabled ?? true;
        if (! $workspaceVisionEnabled) {
            return;
        }

        $detector = match (true) {
            $pdfDetector->supports($document) => $pdfDetector,
            $docxDetector->supports($document) => $docxDetector,
            default => null,
        };
        if (! $detector) {
            return;
        }

        $absolutePath = tempnam(sys_get_temp_dir(), 'visual_');
        file_put_contents($absolutePath, Storage::disk('documents')->get($document->file_path));

        try {
            $refs = $detector->detect($absolutePath);
            if (empty($refs)) {
                return;
            }

            $rasterCache = null; // lazily rasterize once per job, only if PDF refs need it
            $extractedCharts = [];

            foreach ($refs as $ref) {
                [$base64, $mediaType] = $this->resolveImageBytes($ref, $document, $absolutePath, $rasterizer, $rasterCache);
                if ($base64 === null) {
                    continue;
                }

                $result = $client->extractChartDataFromImage($base64, $mediaType, $document);
                foreach ($result['charts'] ?? [] as $chart) {
                    $extractedCharts[] = $chart;
                }
            }

            if (! empty($extractedCharts)) {
                $this->mergeCharts($document, $extractedCharts);
            }
        } finally {
            @unlink($absolutePath);
        }
    }

    private function resolveImageBytes(
        VisualReference $ref,
        Document $document,
        string $absolutePath,
        PdfRasterizer $rasterizer,
        ?array &$rasterCache,
    ): array {
        if ($ref->imageBase64 !== null) {
            return [$ref->imageBase64, $ref->mediaType];
        }

        if ($ref->pageNumber !== null) {
            // Rasterize once, reuse across all flagged pages in this doc.
            $rasterCache ??= $rasterizer->toPageImages($absolutePath);
            $path = $rasterCache[$ref->pageNumber - 1] ?? null;
            if (! $path || ! file_exists($path)) {
                return [null, null];
            }
            return [base64_encode(file_get_contents($path)), 'image/png'];
        }

        return [null, null];
    }

    /**
     * Additive, not destructive — unlike GenerateInsightsJob's
     * delete-then-insert (which owns the full text-derived set), this only
     * appends. A vision failure or empty result must never wipe out charts
     * GenerateInsightsJob already wrote from the document's text.
     */
    private function mergeCharts(Document $document, array $charts): void
    {
        DB::transaction(function () use ($document, $charts) {
            foreach ($charts as $chart) {
                $documentChart = DocumentChart::create([
                    'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    'type' => $chart['type'] ?? 'bar',
                    'title' => $chart['title'] ?? '',
                    'description' => $chart['description'] ?? '',
                    'data' => $chart['data'] ?? [],
                ]);

                foreach ($chart['data'] ?? [] as $sortOrder => $point) {
                    $value = $point['value'] ?? null;
                    if (! is_numeric($value)) {
                        continue;
                    }
                    DocumentChartPoint::create([
                        'document_chart_id' => $documentChart->id,
                        'workspace_id' => $document->workspace_id,
                        'label' => (string) ($point['label'] ?? ''),
                        'value' => (float) $value,
                        'sort_order' => $sortOrder,
                    ]);
                }
            }

            $document->forceFill(['has_structured_data' => true])->save();
        });
    }
}