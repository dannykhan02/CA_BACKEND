<?php

namespace App\Console\Commands;

use App\Http\Resources\ChartResource;
use App\Models\Document;
use App\Services\DocumentIntelligenceService;
use App\Services\Extraction\StructuredExtractionStore;
use App\Services\Intelligence\ModelChartRowMatcher;
use App\Services\Intelligence\ModelChartStructure;
use Illuminate\Console\Command;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Explicit, read-only inspection of one published model chart. Never changes API evidence. */
final class ModelChartDiagnostic extends Command
{
    protected $signature = 'docintel:model-chart-diagnostic {document} {chart} {--match-rows}';

    protected $description = 'Inspect a published AI chart; optionally evaluate experimental PDF row matches';

    public function handle(DocumentIntelligenceService $intelligence, ModelChartStructure $structure,
        StructuredExtractionStore $extractions, ModelChartRowMatcher $matcher): int
    {
        $report = DB::transaction(function () use ($intelligence, $structure, $extractions, $matcher): ?array {
            if (DB::getDriverName() === 'pgsql') {
                DB::statement('SET TRANSACTION READ ONLY');
            }
            $document = Document::query()->whereKey($this->argument('document'))
                ->whereNull('deleted_at')->first();
            if ($document === null) {
                return null;
            }
            $view = $intelligence->publishedView($document);
            $chart = $view->charts()->whereKey($this->argument('chart'))->first();
            if ($chart === null || ($chart->source_metadata['origin'] ?? null) !== 'model_unverified') {
                return null;
            }
            $request = Request::create('/api/documents/'.$document->id);
            $request->attributes->set('chart_published_document', $view);
            $api = (new ChartResource($chart))->resolve($request);
            $result = [
                'document_id' => $document->id,
                'published_revision_id' => $document->published_intelligence_revision_id,
                'chart_id' => (string) $chart->id,
                'saved' => ['type' => $chart->type, 'title' => $chart->title,
                    'description' => $chart->description, 'data' => $chart->data,
                    'source_metadata' => $chart->source_metadata],
                'structure' => $structure->inspect((string) $chart->type, $chart->data,
                    (string) $chart->title),
                'api' => array_intersect_key($api, array_flip([
                    'sourceOrigin', 'evidenceStatus', 'evidenceLabel', 'renderEligible',
                    'unavailabilityReason', 'valueFormat', 'sourcePage', 'sourceRevision',
                    'valuesVerified', 'dimensionsVerified', 'exportSupport',
                ])),
            ];
            if ($this->option('match-rows')) {
                $source = $extractions->active($view);
                $result['row_match'] = ['source_revision' => $source === null ? null
                    : hash('sha256', $source->text),
                    'points' => $source === null ? [] : $matcher->match($source,
                        is_array($chart->data) ? $chart->data : [],
                        is_int($chart->source_metadata['page'] ?? null)
                            ? $chart->source_metadata['page'] : null)];
            }

            return $result;
        });
        if ($report === null) {
            $this->error('Published model chart not found for this document.');

            return self::FAILURE;
        }
        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
            | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
