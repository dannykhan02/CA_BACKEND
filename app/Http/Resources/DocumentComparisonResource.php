<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentComparisonResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $revisions = $this->metadata['source_revisions'] ?? null;
        $provenance = $this->metadata['source_provenance'] ?? 'unknown_historical';
        $revisionStatus = $provenance === 'revision_pinned'
            ? (($this->baseDocument?->published_intelligence_revision_id === ($revisions['base'] ?? null)
                && $this->comparedDocument?->published_intelligence_revision_id === ($revisions['compared'] ?? null))
                ? 'current' : 'outdated')
            : $provenance;

        return [
            'id' => $this->id, 'include_terms' => isset($this->metadata['ai_context']), 'base_document_id' => $this->base_document_id,
            'compared_document_id' => $this->compared_document_id,
            'base_document' => $this->whenLoaded('baseDocument'), 'compared_document' => $this->whenLoaded('comparedDocument'),
            'status' => $this->status, 'summary' => $this->summary, 'changes' => $this->changes,
            'error_message' => $this->error_message, 'created_at' => $this->created_at,
            'limitations' => $this->metadata['limitations'] ?? null,
            'source_revisions' => $revisions, 'revision_status' => $revisionStatus,
        ];
    }
}
