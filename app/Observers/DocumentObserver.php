<?php

namespace App\Observers;

use App\Models\Document;
use App\Services\AuditLogger;

class DocumentObserver
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function updated(Document $document): void
    {
        if (! $document->wasChanged('status')) {
            return;
        }

        $eventMap = [
            'Ready' => 'document.approved',
            'Rejected' => 'document.rejected',
            'Failed' => 'document.failed',
            'Needs Review' => 'document.needs_review',
        ];

        $event = $eventMap[$document->status] ?? null;
        if (! $event) {
            return;
        }

        $this->auditLogger->log(
            auth()->user(),
            $event,
            $document,
            [
                'from' => $document->getOriginal('status'),
                'to' => $document->status,
            ]
        );
    }
}
