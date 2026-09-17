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
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

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
        \App\Services\Vision\ImageFileDetector $imageDetector,
        PdfRasterizer $rasterizer,
    ): void {
        $document = Document::find($this->documentId);
        if (! $document || $document->status !== 'Ready') {
            return;
        }

        $workspaceVisionEnabled = $document->workspace?->aiConfig?->vision_enabled ?? true;
        if (! $workspaceVisionEnabled) {
            return;
        }

        $detector = match (true) {
            $pdfDetector->supports($document) => $pdfDetector,
            $docxDetector->supports($document) => $docxDetector,
            $imageDetector->supports($document) => $imageDetector,
            default => null,
        };
        if (! $detector) {
            return;
        }

        $absolutePath = tempnam(sys_get_temp_dir(), 'visual_');
        file_put_contents($absolutePath, Storage::disk('documents')->get($document->file_path));

        $rasterCache = null;

        try {
            $refs = $detector->detect($absolutePath);
            if (empty($refs)) {
                return;
            }

            $extractedCharts = [];

            foreach ($refs as $ref) {
                try {
                    [$base64, $mediaType] = $this->resolveImageBytes($ref, $absolutePath, $rasterizer, $rasterCache, $refs);
                    if ($base64 === null) {
                        continue;
                    }

                    $result = $client->extractChartDataFromImage($base64, $mediaType, $document);
                    foreach ($result['charts'] ?? [] as $chart) {
                        $extractedCharts[] = $chart;
                    }
                } catch (\Throwable $e) {
                    Log::warning('AnalyzeEmbeddedVisualsJob: failed to process one visual reference', [
                        'document_id' => $document->id,
                        'error' => $e->getMessage(),
                    ]);
                    continue;
                }
            }

            if (! empty($extractedCharts)) {
                $this->mergeCharts($document, $extractedCharts);
            }
        } catch (\Throwable $e) {
            Log::error('AnalyzeEmbeddedVisualsJob failed for document', [
                'document_id' => $document->id,
                'error' => $e->getMessage(),
            ]);
        } finally {
            @unlink($absolutePath);
            if ($rasterCache) {
                $rasterizer->cleanup($rasterCache);
            }
        }
    }

    private function resolveImageBytes(
        VisualReference $ref,
        string $absolutePath,
        PdfRasterizer $rasterizer,
        ?array &$rasterCache,
        array $allRefs,
    ): array {
        if ($ref->imageBase64 !== null) {
            return [$ref->imageBase64, $ref->mediaType];
        }

        if ($ref->pageNumber !== null) {
            if ($rasterCache === null) {
                $neededPages = array_values(array_unique(array_filter(
                    array_map(fn ($r) => $r->pageNumber, $allRefs)
                )));
                $rasterCache = $rasterizer->toPageImages($absolutePath, $neededPages);
            }
            $path = $rasterCache[$ref->pageNumber] ?? null;
            if (! $path || ! file_exists($path)) {
                return [null, null];
            }
            return [base64_encode(file_get_contents($path)), 'image/png'];
        }

        return [null, null];
    }

    private function mergeCharts(Document $document, array $charts): void
    {
        $existingTitles = $document->charts()->pluck('title')
            ->map(fn ($t) => strtolower(trim($t)))
            ->all();

        $charts = array_values(array_filter($charts, function ($chart) use ($existingTitles) {
            $title = strtolower(trim($chart['title'] ?? ''));
            return $title === '' || ! in_array($title, $existingTitles, true);
        }));

        if (empty($charts)) {
            return;
        }

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
