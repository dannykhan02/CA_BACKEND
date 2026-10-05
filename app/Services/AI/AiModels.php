<?php

namespace App\Services\AI;

class AiModels
{
    public function forTask(string $task): string
    {
        if (in_array($task, ['document_summary', 'context_resolution'], true)) {
            return config('services.anthropic.synthesis_model') ?: config('services.anthropic.model');
        }

        if (in_array($task, ['extraction', 'entities', 'insights', 'risks', 'deadlines', 'document_type'], true)) {
            return config('services.anthropic.extraction_model') ?: config('services.anthropic.model');
        }

        return config('services.anthropic.model');
    }
}
