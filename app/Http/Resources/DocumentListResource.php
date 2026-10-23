<?php

namespace App\Http\Resources;

use App\Services\AiCredits\DocumentCredits;
use App\Services\AiCredits\QuoteService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentListResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'intelligenceRevisionId' => $this->published_intelligence_revision_id,
            'intelligenceAvailable' => $this->published_intelligence_revision_id !== null
                || (int) $this->intelligence_generation === 0 && $this->status === 'Ready',
            'intelligenceRefreshInProgress' => $this->active_intelligence_revision_id !== null,
            'name' => $this->name,
            'type' => $this->type,
            'sizeKb' => $this->size_kb,
            // "A storage key is recorded on the row" — **not** "the bytes are
            // there". Deliberately answered from the model and never from the
            // disk: a list page renders many of these, and one `exists()` per
            // row would be an N+1 of round trips against R2 on every render.
            // A client that needs to know the object really resolves has to
            // ask for it; this field only lets the UI stop offering a download
            // for a document that never had a file, instead of sending the
            // reader into an error.
            'hasOriginal' => $this->hasRecordedOriginal(),
            'status' => $this->status,
            'classification' => $this->classification,
            'year' => $this->year,
            'uploadedAt' => $this->created_at?->toIso8601String(),
            'uploadedBy' => $this->uploader?->full_name,
            'lastUpdatedBy' => $this->lastUpdater?->full_name,
            'lastUpdatedAt' => $this->updated_at?->toIso8601String(),
            'pages' => $this->published_intelligence_revision_id
                ? $this->publishedIntelligenceRevision?->pages : $this->pages,
            'hasStructuredData' => $this->published_intelligence_revision_id
                ? (bool) $this->publishedIntelligenceRevision?->has_structured_data
                : ((int) $this->intelligence_generation === 0 && $this->has_structured_data),
            'powerBIStatus' => $this->power_bi_status,
            'progress' => $this->progress,
            // Only with workload credits on; null until the document has a quote (the quote is made when processing starts).
            $this->mergeWhen(QuoteService::enabled(), fn () => ['credits' => app(DocumentCredits::class)->for($this->resource)]),
            'errorMessage' => $this->error_message,
            'pageFlags' => [],
            'kpis' => [],
            'charts' => [],
            'insights' => [],
        ];
    }
}
