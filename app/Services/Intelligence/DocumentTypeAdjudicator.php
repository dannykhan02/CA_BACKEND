<?php

namespace App\Services\Intelligence;

use App\Models\Document;

/** Resolves the narrow report-versus-minutes ambiguity from document content. */
class DocumentTypeAdjudicator
{
    public function adjudicate(Document $document, array $classification): array
    {
        if (($classification['document_type'] ?? null) !== 'meeting_minutes') {
            return $classification;
        }
        $text = mb_strtolower((string) $document->extracted_text);
        $minutesMarkers = [
            '/\bminutes\s+of\s+(?:the\s+)?meeting\b/u',
            '/\b(?:attendees|apologies|members present|meeting called to order)\b/u',
            '/\b(?:motion carried|minutes approved|agenda item|resolution passed)\b/u',
        ];
        $minutes = count(array_filter($minutesMarkers, fn ($pattern) => preg_match($pattern, $text) === 1));
        if ($minutes >= 1) {
            return $classification;
        }
        $finance = preg_match('/\b(?:revenue|turnover|sales)\b/u', $text) === 1
            && preg_match('/\b(?:operating costs?|expenses?|profit|margin)\b/u', $text) === 1;
        $operations = preg_match('/\b(?:delivery|shipments?|complaints?|service levels?|production|operations?)\b/u', $text) === 1;
        $performance = preg_match('/\b(?:quarterly|q[1-4]\s*20\d{2}|performance review|performance report|actual versus target)\b/u', $text) === 1;
        if (! ($finance && $operations && $performance)) {
            return $classification;
        }

        return array_replace($classification, [
            'document_type' => 'financial_report',
            'confidence' => min(0.85, max(0.6, (float) ($classification['confidence'] ?? 0))),
            'reasoning' => 'The document presents period-based financial and operational performance rather than a meeting record.',
        ]);
    }
}
