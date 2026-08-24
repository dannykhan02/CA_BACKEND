<?php

namespace App\Jobs;

use App\Jobs\Concerns\SkipsUnchangedDocuments;
use App\Models\Document;
use App\Models\DocumentChart;
use App\Models\DocumentChartPoint;
use App\Models\DocumentKpi;
use App\Services\AnthropicClient;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GenerateInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels, SkipsUnchangedDocuments;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public string $documentId, public bool $forceReprocess = false) {}

    public function handle(AnthropicClient $client, PipelineStageRecorder $recorder): void
    {
        $document = Document::find($this->documentId);

        // Only proceed if extraction actually left the document in the
        // in-progress state this stage expects. Two prior stages can leave
        // it somewhere else WITHOUT throwing (so the chain isn't cancelled):
        // fallbackToOcr()'s "OCR found no readable text" branch sets
        // 'Needs Review' and returns null without calling $this->fail(), and
        // the ineligible-type/ocr-disabled branch does call $this->fail(),
        // which correctly cancels the chain — but we guard here anyway in
        // case this job is ever dispatched standalone (see
        // DocumentReprocessController) against a document that isn't
        // mid-pipeline
        if (! $document || $document->status !== 'Processing') {
            return;
        }

        // Unlike the other intelligence jobs, this one owns the document's
        // terminal status transition — a skip must still finalize the
        // document as Ready, or it would be stuck at 'Processing' forever.
        if ($this->skipIfUnchanged($document, 'insights', 'ai_analysis', $recorder, ['status' => 'Ready', 'progress' => 100])) {
            return;
        }
        

        $text = $document->extracted_text;
        if (! $text || trim($text) === '') {
            // Shouldn't normally be reachable — extraction sets 'Needs
            // Review'/'Failed' before this job would see status
            // 'Processing' with empty text — but fail loudly rather than
            // silently produce a Ready document with no insights.
            $document->forceFill([
                'status' => 'Failed',
                'error_message' => 'No extracted text available for AI analysis.',
            ])->save();
            $this->fail(new \RuntimeException('Empty extracted_text at insights stage.'));
            return;
        }

        $insightsStage = $recorder->start($document, 'ai_analysis');

        try {
            $result = $client->extractDocumentInsights($text, $document->name, $document);
        } catch (\Throwable $e) {
            $recorder->fail($insightsStage, $e->getMessage());
            $document->forceFill([
                'status' => 'Failed',
                'error_message' => 'AI analysis failed: ' . $e->getMessage(),
            ])->save();
            $this->fail($e);
            return;
        }

        $kpis = $result['kpis'] ?? [];
        $charts = $result['charts'] ?? [];
        $insights = $result['insights'] ?? [];

        DB::transaction(function () use ($document, $kpis, $charts, $insights) {
            // Delete-before-insert — a reprocessed document must not leave
            // stale kpis/charts from a prior version sitting alongside the
            // current ones. Same reasoning as GenerateEmbeddingsJob's
            // chunk replacement.
            DocumentKpi::where('document_id', $document->id)->delete();
            DocumentChart::where('document_id', $document->id)->delete();

            foreach ($kpis as $kpi) {
                DocumentKpi::create([
                    'workspace_id' => $document->workspace_id,
                    'document_id' => $document->id,
                    'label' => $kpi['label'] ?? '',
                    'value' => $kpi['value'] ?? '',
                    'value_numeric' => is_numeric($kpi['value'] ?? null) ? (float) $kpi['value'] : null,
                    'unit' => $kpi['unit'] ?? null,
                    'trend' => $kpi['trend'] ?? null,
                    'trend_value' => $kpi['trendValue'] ?? null,
                ]);
            }

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

            $document->forceFill([
                'insights' => $insights,
                'has_structured_data' => ! empty($kpis) || ! empty($charts),
                'status' => 'Ready',
                'progress' => 100,
            ])->save();
        });

        $recorder->complete($insightsStage, [
            'kpi_count' => count($kpis),
            'chart_count' => count($charts),
            'insight_count' => count($insights),
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $document = Document::find($this->documentId);
        $document?->forceFill([
            'status' => 'Failed',
            'error_message' => $document->error_message ?? 'AI insights generation failed.',
        ])->save();

        Log::error('GenerateInsightsJob failed after retries', [
            'document_id' => $this->documentId,
            'error' => $e->getMessage(),
        ]);
    }
}




