<?php

namespace App\Services\Pipeline;

use App\Models\Document;

/** Persisted document states; queue delivery is never authority to resume terminal work. */
class DocumentStagePolicy
{
    public const TERMINAL_FAILURES = ['Failed', 'Needs Review'];

    public function permits(Document $document, string $stage): bool
    {
        if ($document->trashed() || in_array($document->status, self::TERMINAL_FAILURES, true)) {
            return false;
        }

        return match ($stage) {
            'scan', 'extract', 'ocr' => $document->status === 'Processing',
            'brief_synthesis', 'visual' => $document->status === 'Ready',
            'intelligence' => $document->canGenerateIntelligence(),
            'kpi_identity' => $document->canGenerateIntelligence(requiresExtractedText: false),
            default => false,
        };
    }
}
