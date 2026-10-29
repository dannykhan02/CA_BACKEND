<?php

namespace App\Services;

use App\Models\DocumentDeadline;
use App\Models\TrackedItem;
use App\Models\User;

class TrackingService
{
    public function __construct(private IntelligenceAccess $access) {}

    public function sourceKey(DocumentDeadline $source): string
    {
        return hash('sha256', $source->title.'|'.$source->evidence);
    }

    public function track(User $user, string $documentId, int $deadlineId): TrackedItem
    {
        $document = $this->access->document($user, $documentId, true);
        $document = app(DocumentIntelligenceService::class)->publishedView($document);
        $source = $document->deadlines()->findOrFail($deadlineId);
        // Natural evidence fingerprint survives delete/recreate extraction jobs.
        $key = $this->sourceKey($source);

        return TrackedItem::firstOrCreate(['document_id' => $document->id, 'source_key' => $key], [
            'workspace_id' => $document->workspace_id, 'matter_id' => $document->matter_id,
            'created_by' => $user->id, 'title' => $source->title, 'type' => $source->deadline_type ?: 'obligation',
            'due_date' => $source->due_date, 'source' => [
                'insight_id' => $source->id, 'document_id' => $document->id, 'evidence' => $source->evidence,
                'date_type' => $source->date_type, 'relative_text' => $source->relative_text,
                'confidence' => (float) $source->confidence, 'description' => $source->description,
                'extracted_due_date' => $source->due_date?->toDateString(),
            ],
        ]);
    }
}
