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
            'content' => $this->content,
            'contentFormat' => $this->content_format,
            'contentSchemaVersion' => $this->content_format === 'tiptap_v1' ? 1 : null,
            'version' => $this->version,
            'references' => $this->references->map(fn ($reference) => [
                'id' => $reference->id, 'referenceType' => $reference->reference_type,
                'findingId' => $reference->finding_id, 'intelligenceRevisionId' => $reference->intelligence_revision_id,
                'label' => $reference->label, 'sourceId' => $reference->source_id,
                'page' => $reference->page, 'quoteSnapshot' => $reference->quote_snapshot,
                'state' => $this->reference_states[$reference->id] ?? 'unavailable',
            ])->all(),
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
