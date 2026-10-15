<?php

namespace App\Services\Intelligence;

use App\Models\Document;
use App\Models\DocumentChart;
use Illuminate\Support\Facades\Storage;

class SourceChartAccess
{
    public function path(Document $document, DocumentChart $chart, bool $checkExists = true): ?string
    {
        $source = $chart->source_metadata;
        $pipelineKey = $document->ai_pipeline['key'] ?? hash('sha256', $document->workspace_id.'|'.$document->id.'|'.$document->file_hash.'|visual-v1');
        if (! is_array($source) || $chart->document_id !== $document->id
            || $chart->workspace_id !== $document->workspace_id
            || $chart->intelligence_revision_id !== $document->intelligenceRevisionForRelations()
            || ! is_string($source['path'] ?? null)
            || ! is_string($source['sha256'] ?? null)
            || ! preg_match('/^[a-f0-9]{64}$/', $source['sha256'])
            || ($source['document_hash'] ?? null) !== $document->file_hash
            || ($source['pipeline_key'] ?? null) !== $pipelineKey
            || ! in_array($source['media_type'] ?? null, ['image/png', 'image/jpeg', 'image/webp'], true)) {
            return null;
        }
        $expected = 'chart-visuals/'.$document->workspace_id.'/'.$document->id.'/'
            .($chart->intelligence_revision_id ?? 'legacy').'/'.$source['sha256'];
        if ($source['path'] !== $expected) {
            return null;
        }

        if ($checkExists) {
            try {
                $exists = Storage::disk('documents')->exists($expected);
            } catch (\Throwable) {
                return null;
            }
            if (! $exists) {
                return null;
            }
        }

        return $expected;
    }
}
