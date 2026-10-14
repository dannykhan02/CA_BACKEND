<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentNoteResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'documentId' => $this->document_id,
            'body' => $this->body,
            'version' => $this->version,
            'citations' => $this->citations->map(fn ($citation) => [
                'id' => $citation->id,
                'intelligenceRevisionId' => $citation->intelligence_revision_id,
                'sourceId' => $citation->source_id,
                'findingLabel' => $citation->finding_label,
                'quoteSnapshot' => $citation->quote_snapshot,
                'page' => $citation->page,
                'verificationStatus' => 'unverified_snapshot',
            ])->all(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
