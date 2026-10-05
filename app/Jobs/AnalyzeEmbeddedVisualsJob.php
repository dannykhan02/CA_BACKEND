<?php

namespace App\Jobs;

use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentChartPoint;
use App\Models\DocumentChunk;
use App\Services\AI\Incremental\VisualPlanner;
use App\Services\AnthropicClient;
use App\Services\Documents\DocumentStorageService;
use App\Services\EntitlementService;
use App\Services\Ocr\PdfRasterizer;
use App\Services\Vision\DocxImageDetector;
use App\Services\Vision\ImageFileDetector;
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

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $documentId) {}

    public function handle(
        AnthropicClient $client,
        PdfImageDetector $pdfDetector,
        DocxImageDetector $docxDetector,
        ImageFileDetector $imageDetector,
        PdfRasterizer $rasterizer,
    ): void {
        $document = Document::find($this->documentId);
        if (! $document || $document->status !== 'Ready') {
            return;
        }

        $key = $document->ai_pipeline['key'] ?? hash('sha256', $document->workspace_id.'|'.$document->id.'|'.$document->file_hash.'|visual-v1');
        $plan = DocumentChunk::firstOrCreate(['document_id' => $document->id, 'pipeline_key' => $key, 'identity' => 'visual-plan'],
            ['workspace_id' => $document->workspace_id, 'stage' => 'visual_plan', 'input_hash' => $key,
                'pipeline_version' => config('document_intelligence.pipeline_version'), 'prompt_version' => '1']);
        if (! DocumentChunk::whereKey($plan->id)->where('status', 'pending')->update([
            'status' => 'running', 'started_at' => now(), 'attempts' => 1])) {
            return;
        }

        $workspaceVisionEnabled = $document->workspace?->aiConfig?->vision_enabled ?? true;

        $detector = match (true) {
            $pdfDetector->supports($document) => $pdfDetector,
            $docxDetector->supports($document) => $docxDetector,
            $imageDetector->supports($document) => $imageDetector,
            default => null,
        };
        if (! $workspaceVisionEnabled || ! $detector) {
            $plan->update(['status' => 'skipped', 'completed_at' => now()]);

            return;
        }
        if (! $document->credit_accounted_at) {
            app(EntitlementService::class)->reserveDocument($document);
        }

        $absolutePath = null;

        $rasterCache = null;

        try {
            $absolutePath = app(DocumentStorageService::class)->temporaryCopy($document->file_path, 'visual_');
            $refs = $detector->detect($absolutePath);
            if (empty($refs)) {
                $plan->update(['status' => 'completed', 'completed_at' => now()]);

                return;
            }

            $key = $document->ai_pipeline['key'] ?? hash('sha256', $document->workspace_id.'|'.$document->id.'|'.$document->file_hash.'|visual-v1');
            $planner = app(VisualPlanner::class);
            $seen = [];
            foreach (array_slice($refs, 0, config('document_intelligence.visual_cap')) as $ref) {
                [$base64, $mediaType] = $this->resolveImageBytes($ref, $absolutePath, $rasterizer, $rasterCache, [$ref]);
                if ($rasterCache) {
                    $rasterizer->cleanup($rasterCache);
                    $rasterCache = null;
                }
                if ($base64 === null) {
                    continue;
                }
                $bytes = base64_decode($base64, true);
                if ($bytes === false || ! $planner->useful($bytes)) {
                    continue;
                }
                $hash = hash('sha256', $bytes);
                if (isset($seen[$hash])) {
                    continue;
                }
                $seen[$hash] = true;
                $identity = 'visual:'.$hash;
                if (DocumentChunk::where('document_id', $document->id)->where('pipeline_key', $key)->where('identity', $identity)->exists()) {
                    continue;
                }
                $path = 'ai-visuals/'.$document->workspace_id.'/'.$document->id.'/'.$hash;
                Storage::disk('documents')->put($path, $bytes);
                DocumentChunk::firstOrCreate(['document_id' => $document->id, 'pipeline_key' => $key, 'identity' => $identity],
                    ['workspace_id' => $document->workspace_id, 'stage' => 'visual', 'input_hash' => $hash,
                        'pipeline_version' => config('document_intelligence.pipeline_version'), 'prompt_version' => '1',
                        'result' => ['path' => $path, 'media_type' => $mediaType, 'page' => $ref->pageNumber]]);
                if ($rasterCache) {
                    $rasterizer->cleanup($rasterCache);
                    $rasterCache = null;
                }
            }
            $plan->update(['status' => 'completed', 'completed_at' => now()]);
            $planner->pump($document, $key);

        } catch (\Throwable $e) {
            $plan->update(['status' => 'failed', 'failure_class' => 'visual_planning', 'completed_at' => now()]);
            Log::error('AnalyzeEmbeddedVisualsJob failed for document', [
                'document_id' => $document->id,
                'error_type' => $e::class,
            ]);
        } finally {
            if ($absolutePath) {
                @unlink($absolutePath);
            }
            app(VisualPlanner::class)->pump($document, $key);
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

    public function mergeCharts(Document $document, array $charts): void
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
