<?php

namespace App\Jobs;

use App\Exceptions\AnthropicRateLimitException;
use App\Models\Document;
use App\Services\AnthropicClient;
use App\Services\DocumentTextExtractor;
use App\Services\Pipeline\PipelineStageRecorder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class GenerateInsightsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 90;
    public int $backoff = 15;

    public function __construct(public string $documentId) {}

    public function handle(AnthropicClient $client, DocumentTextExtractor $extractor, PipelineStageRecorder $recorder): void
    {
        $document = Document::find($this->documentId);
        if (! $document || $document->status === 'Failed') {
            return;
        }

        // Classification gate — Restricted (and anything else not in the
        // allow-list) never gets its content sent to Anthropic automatically.
        $allowed = config('document_processing.auto_extract_classifications');
        if (! in_array($document->classification, $allowed, true)) {
            $recorder->skip($document, 'ai_analysis', 'classification not in auto-extract allow-list');
            $document->forceFill([
                'status' => 'Needs Review',
                'progress' => 100,
                'error_message' => null,
            ])->save();
            return;
        }

        $text = Cache::get("document:{$document->id}:extracted_text");

        if (! $text) {
            // Cache TTL (2hrs) can easily lapse between initial extraction and
            // a manual reprocess request — e.g. a Reviewer picking up a Needs
            // Review document the next day. Rather than failing outright,
            // re-extract from the original stored file instead of assuming
            // the document is broken.
            Log::info("Extraction cache miss on reprocess — re-extracting from disk for document {$document->id}");

            try {
                $absolutePath = Storage::disk('documents')->path($document->file_path);
                $text = $document->type === 'PDF'
                    ? $extractor->extractPdfText($absolutePath)
                    : $extractor->extractDocxText($absolutePath);
            } catch (\Throwable $e) {
                Log::error('Re-extraction on reprocess failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);
                $document->forceFill([
                    'status' => 'Failed',
                    'error_message' => 'Could not re-extract document text for reprocessing.',
                ])->save();
                return;
            }

            if (trim($text) === '') {
                $document->forceFill([
                    'status' => 'Failed',
                    'error_message' => 'No extractable text found on re-extraction.',
                ])->save();
                return;
            }

            // Re-populate the cache so a retry within this job's own lifecycle,
            // or a near-immediate second reprocess, doesn't hit disk again.
            Cache::put("document:{$document->id}:extracted_text", $text, now()->addHours(2));
        }

        $document->forceFill(['progress' => 75, 'extraction_started_at' => now()])->save();

        $aiStage = $recorder->start($document, 'ai_analysis');

        try {
            $result = $client->extractDocumentInsights($text, $document->name);
        } catch (AnthropicRateLimitException $e) {
            // Not a stage failure — the job itself retries via ->release(),
            // so no processing_jobs row is finalized here. A fresh 'ai_analysis'
            // row will be started on the next attempt instead of leaving this
            // one dangling in 'processing'.
            $recorder->fail($aiStage, 'Rate limited, releasing for retry.');
            $this->release(30);
            return;
        } catch (\Throwable $e) {
            Log::error('Insight generation failed', ['document_id' => $document->id, 'error' => $e->getMessage()]);

            // Only downgrade to a terminal state on the final attempt —
            // otherwise rethrow so Laravel's native retry/backoff (tries=3)
            // actually gets a chance to run, rather than this catch silently
            // swallowing every attempt and making $tries meaningless.
            if ($this->attempts() >= $this->tries) {
                $recorder->fail($aiStage, $e->getMessage());
                $document->forceFill([
                    'status' => 'Needs Review',
                    'error_message' => 'AI insight generation failed. Contact support if this persists.',
                ])->save();
                return;
            }

            $recorder->fail($aiStage, $e->getMessage() . ' (will retry)');
            throw $e;
        }

        $recorder->complete($aiStage, [
            'input_tokens' => $result['input_tokens'],
            'output_tokens' => $result['output_tokens'],
        ]);

        $document->forceFill([
            'status' => 'Ready',
            'has_structured_data' => ! empty($result['kpis']) || ! empty($result['charts']),
            'insights' => $result['insights'],
            'progress' => 100,
            'extraction_completed_at' => now(),
            'extraction_input_tokens' => $result['input_tokens'],
            'extraction_output_tokens' => $result['output_tokens'],
            'error_message' => null,
        ])->save();

        // Clear any prior KPIs/charts before inserting fresh ones — otherwise
        // a reprocessed document (via DocumentReprocessController) accumulates
        // duplicate rows on every retry rather than replacing stale data.
        // Note: deleting a chart row cascades to its document_chart_points
        // rows automatically via the DB foreign key — no extra cleanup needed.
        $kpiStage = $recorder->start($document, 'kpis');
        $document->kpis()->delete();

        foreach ($result['kpis'] as $kpi) {
            $document->kpis()->create([
                'label' => $kpi['label'],
                'value' => $kpi['value'],
                'value_numeric' => $this->parseNumericValue((string) $kpi['value']),
                'unit' => $kpi['unit'] ?? null,
                'trend' => $kpi['trend'] ?? null,
                'trend_value' => $kpi['trendValue'] ?? null,
            ]);
        }
        $recorder->complete($kpiStage, ['count' => count($result['kpis'])]);

        $chartStage = $recorder->start($document, 'charts');
        $document->charts()->delete();

        foreach ($result['charts'] as $chart) {
            $chartModel = $document->charts()->create([
                'type' => $chart['type'],
                'title' => $chart['title'],
                'description' => $chart['description'],
                'data' => $chart['data'],
            ]);

            foreach ($chart['data'] as $index => $point) {
                $chartModel->points()->create([
                    'label' => $point['label'],
                    'value' => $point['value'],
                    'sort_order' => $index,
                ]);
            }
        }
        $recorder->complete($chartStage, ['count' => count($result['charts'])]);

        Cache::forget("document:{$document->id}:extracted_text");
    }

    /**
     * Parses a human/AI-written value string into a real number for BI/reporting
     * consumers. Handles suffixes (K/M/B), currency prefixes, and units glued
     * onto the number (e.g. "4.2 days" -> 4.2, "KES 312" -> 312, "64.2M" -> 64200000).
     * Returns null when no numeric value can reasonably be extracted — callers
     * must treat null as "not chartable", never coerce to zero.
     */
    private function parseNumericValue(string $value): ?float
    {
        if (! preg_match('/(-?[\d,]+\.?\d*)\s*([KMB])?/i', $value, $matches)) {
            return null;
        }

        $number = (float) str_replace(',', '', $matches[1]);
        $suffix = strtoupper($matches[2] ?? '');

        return match ($suffix) {
            'K' => $number * 1_000,
            'M' => $number * 1_000_000,
            'B' => $number * 1_000_000_000,
            default => $number,
        };
    }

    public function failed(\Throwable $e): void
    {
        $document = Document::find($this->documentId);
        $document?->forceFill([
            'status' => 'Needs Review',
            'error_message' => 'AI insight generation failed after retries. Contact support if this persists.',
        ])->save();
    }
}