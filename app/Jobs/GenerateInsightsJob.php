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
                    'value_numeric' => $this->parseNumericValue($kpi['value'] ?? null),
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

                $sortOrder = 0;
                foreach ($chart['data'] ?? [] as $point) {
                    $value = $point['value'] ?? null;
                    if (! is_numeric($value)) {
                        continue;
                    }

                    $label = (string) ($point['label'] ?? '');
                    if ($this->looksLikeTargetOrThreshold($label)) {
                        // Prompt v3 already instructs the model not to plot
                        // a mandated target/threshold as a series value, but
                        // the model does not reliably follow this — verified
                        // against a real document where "Target" still
                        // appeared as a plotted point despite the rule.
                        // Enforced here in code instead, since chart-point
                        // labels are a controlled-enough vocabulary that a
                        // keyword filter is a safe backstop, not a guess.
                        continue;
                    }

                    DocumentChartPoint::create([
                        'document_chart_id' => $documentChart->id,
                        'workspace_id' => $document->workspace_id,
                        'label' => $label,
                        'value' => (float) $value,
                        'sort_order' => $sortOrder,
                    ]);
                    $sortOrder++;
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

    /**
     * The model sometimes bakes a unit directly into `value` (e.g. "79.8%",
     * "1,234") instead of using the separate `unit` field. is_numeric() fails
     * on both, which silently null'd value_numeric for every percentage KPI.
     * Strip trailing '%' and thousands separators before testing numericness
     * so these still get a usable value_numeric.
     */
    /**
     * Case-insensitive match against common ways a model labels a
     * mandated target/threshold rather than a measured value — e.g.
     * "Target", "90% Target", "Mandated Target", "Threshold". Deliberately
     * conservative: matches only when the label is short and clearly a
     * target marker, not a real data-series label that happens to contain
     * one of these words as part of a longer phrase (e.g. a regional
     * centre literally named "Target Zone" would not match, since the
     * check requires the label to be dominated by target-language, not
     * merely contain it).
     */
    private function looksLikeTargetOrThreshold(string $label): bool
    {
        $normalized = strtolower(trim($label));
        if ($normalized === '') {
            return false;
        }

        $targetWords = ['target', 'threshold', 'mandated', 'goal', 'benchmark'];
        foreach ($targetWords as $word) {
            if (str_contains($normalized, $word)) {
                // Only treat it as a target marker if target-language makes
                // up a large share of the label — avoids over-matching a
                // longer, legitimate data-series label.
                if (strlen($normalized) <= strlen($word) + 15) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The model sometimes bakes a unit directly into `value` (e.g. "79.8%",
     * "1,234") instead of using the separate `unit` field. is_numeric() fails
     * on both, which silently null'd value_numeric for every percentage KPI.
     * Strip trailing '%' and thousands separators before testing numericness
     * so these still get a usable value_numeric.
     */
    private function parseNumericValue(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $stripped = rtrim(str_replace(',', '', trim($value)), '%');

        return is_numeric($stripped) ? (float) $stripped : null;
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