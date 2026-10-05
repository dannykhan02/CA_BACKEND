<?php

namespace App\Services\AI;

class AiModels
{
    public function forTask(string $task): string
    {
        if (in_array($task, ['document_summary', 'synthesis', 'summary_repair', 'document_comparison', 'document_qa'], true)) {
            return config('services.anthropic.synthesis_model') ?: 'claude-sonnet-5-5';
        }

        if (in_array($task, ['extraction', 'entities', 'insights', 'risks', 'deadlines', 'document_type', 'classification', 'context_resolution', 'repair', 'ocr', 'chart_vision', 'kpi_identity'], true)) {
            return config('services.anthropic.extraction_model') ?: config('services.anthropic.model');
        }

        return config('services.anthropic.model');
    }
}
