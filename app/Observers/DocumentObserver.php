<?php

namespace App\Observers;

use App\Models\Document;
use App\Services\AuditLogger;

class DocumentObserver
{
    public function __construct(private AuditLogger $auditLogger) {}

    public function updated(Document $document): void
    {
        if ($document->wasChanged('status')) {
            $this->handleStatusChange($document);
        }

        // power_bi_status must reflect reality even if classification
        // changes on an already-processed document with no status change
        // at all — e.g. a Ready document reclassified to Restricted post-
        // upload. Without this branch, that document would stay marked
        // 'synced' in the app's own documents table while power_bi_kpis /
        // power_bi_chart_points (correctly) already exclude it — a silent
        // drift between what the app believes and what Power BI can see.
        if ($document->wasChanged('classification') && ! $document->wasChanged('status')) {
            $this->syncPowerBiStatus($document);
        }
    }

    private function handleStatusChange(Document $document): void
    {
        $eventMap = [
            'Ready' => 'document.approved',
            'Rejected' => 'document.rejected',
            'Failed' => 'document.failed',
            'Needs Review' => 'document.needs_review',
        ];

        $event = $eventMap[$document->status] ?? null;

        if ($event) {
            // auth()->user() is always null here — this fires from Horizon
            // workers with no HTTP session. Use the document's own uploader
            // instead, and pass workspace_id explicitly rather than relying
            // on AuditLogger's subject-based fallback, since intent here is
            // explicit, not incidental.
            $this->auditLogger->log(
                $document->uploader,
                $event,
                $document,
                [
                    'from' => $document->getOriginal('status'),
                    'to' => $document->status,
                ],
                $document->workspace_id
            );
        }

        $this->syncPowerBiStatus($document);
    }

    /**
     * Mirrors power_bi_kpis / power_bi_chart_points' own WHERE clause
     * exactly (classification != 'Restricted' AND status = 'Ready') — this
     * must never drift from those two views' actual filter logic, since
     * this column is purely a reflection of what Power BI can already see,
     * not an independent source of truth.
     */
    private function syncPowerBiStatus(Document $document): void
    {
        $shouldBeVisible = $document->status === 'Ready'
            && $document->classification !== 'Restricted';

        $newStatus = match (true) {
            $shouldBeVisible => 'synced',
            $document->classification === 'Restricted' => 'excluded',
            default => 'not-synced',
        };

        if ($document->power_bi_status !== $newStatus) {
            // forceFill + save, not update(), to avoid re-triggering this
            // same updated() observer recursively on a nested save call —
            // saveQuietly() suppresses model events for this one write.
            $document->forceFill(['power_bi_status' => $newStatus])->saveQuietly();
        }
    }
}
