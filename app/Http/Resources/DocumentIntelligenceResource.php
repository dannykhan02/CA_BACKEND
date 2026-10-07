<?php

namespace App\Http\Resources;

use App\Models\DocumentEvidence;
use App\Services\DocumentIntelligenceService;
use App\Services\Documents\EvidencePageLocator;
use App\Services\Intelligence\DocumentAnalysisComposer;
use App\Services\Intelligence\ImportantFindingsBuilder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Composite read-only response. Reuses DocumentTypeClassificationResource/
 * DocumentEntityResource/DocumentRiskResource/DocumentDeadlineResource/
 * DocumentIntelligenceSummaryResource for consistency with the dedicated
 * single-purpose endpoints — same field shapes everywhere, not a second
 * formatting convention.
 *
 * `analysis` is the derived, presentation-ready view of the same stored evidence:
 * key takeaways, chart candidates, analysis groups and important findings, all
 * produced by DocumentAnalysisComposer from evidence the document already has.
 * It is present once a document has finished processing; while a document is
 * still being analyzed the page shows processing state instead, so deriving it
 * on every poll would be work nobody reads.
 *
 * `evidence` carries source excerpts only for the references this response
 * actually points at: every risk, deadline and entity it returns, the synthesis'
 * own citations, and what `analysis` renders. Before that restriction a rich
 * document also shipped the full quote of every metric finding — commonly over a
 * hundred row-level table figures that nothing on the page references.
 */
class DocumentIntelligenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $service = app(DocumentIntelligenceService::class);
        $locator = app(EvidencePageLocator::class);
        $sourcePages = [];
        foreach (['risk' => ['risks', 'evidence'], 'deadline' => ['deadlines', 'evidence'], 'entity' => ['entities', 'context']] as $kind => [$relation, $field]) {
            foreach ($this->resource->$relation as $item) {
                $page = $locator->locate($this->extracted_text, (int) $this->pages, $item->$field);
                if ($page !== null) {
                    $sourcePages[$kind.':'.$item->id] = $page;
                }
            }
        }

        $composer = app(DocumentAnalysisComposer::class);
        $analysis = in_array($this->status, ['Ready', 'Needs Review'], true)
            ? $composer->compose($this->resource)
            : null;
        $referenced = array_fill_keys($analysis === null ? [] : $composer->referencedSourceIds($analysis), true);
        foreach (app(ImportantFindingsBuilder::class)->citedSourceIds($this->resource->intelligenceSummary) as $cited) {
            $referenced[$cited] = true;
        }
        // The risks, deadlines and entities this response returns are all rendered, so their
        // evidence - including its span locations - stays available exactly as before.
        foreach (['risk' => 'risks', 'deadline' => 'deadlines', 'entity' => 'entities'] as $kind => $relation) {
            foreach ($this->resource->$relation as $item) {
                $referenced[$kind.':'.$item->id] = true;
            }
        }

        $evidence = [];
        if (isset($this->ai_pipeline['key'])) {
            foreach (DocumentEvidence::where('document_id', $this->id)->where('workspace_id', $this->workspace_id)
                ->where('pipeline_key', $this->ai_pipeline['key'])->cursor() as $item) {
                if ($item->source_id === null || ! isset($referenced[$item->source_id])) {
                    continue;
                }
                $evidence[$item->source_id] = ['quote' => $item->data['quote'], 'sources' => $item->sources];
                $pages = array_unique(array_filter(array_column($item->sources, 'page')));
                if (count($pages) === 1) {
                    $sourcePages[$item->source_id] = reset($pages);
                }
            }
        }

        return [
            'document' => [
                'id' => $this->id,
                'name' => $this->name,
                'errorMessage' => $this->error_message,
                'type' => $this->type,
                'status' => $this->status,
                'processingFailure' => app(DocumentIntelligenceService::class)->getDocumentFailure($this->resource),
            ],
            'documentType' => $this->whenLoaded(
                'documentTypeClassification',
                fn () => $this->documentTypeClassification
                    ? new DocumentTypeClassificationResource($this->documentTypeClassification)
                    : null
            ),
            'summary' => [
                'entities' => $this->whenLoaded('entities', fn () => $this->entities->count(), 0),
                'risks' => $this->whenLoaded('risks', fn () => $this->risks->count(), 0),
                'deadlines' => $this->whenLoaded('deadlines', fn () => $this->deadlines->count(), 0),
            ],
            'entities' => DocumentEntityResource::collection($this->whenLoaded('entities')),
            'risks' => DocumentRiskResource::collection($this->whenLoaded('risks')),
            'deadlines' => DocumentDeadlineResource::collection($this->whenLoaded('deadlines')),
            'intelligenceSummary' => $this->whenLoaded(
                'intelligenceSummary',
                fn () => $this->intelligenceSummary
                    ? new DocumentIntelligenceSummaryResource($this->intelligenceSummary)
                    : null
            ),
            'analysis' => $analysis,
            'evidence' => (object) $evidence,
            'processingDetails' => $service->processingDetails($this->resource),
            'processing' => $service->getProcessingStatus($this->resource),
            'sourcePages' => (object) $sourcePages,
        ];
    }
}
