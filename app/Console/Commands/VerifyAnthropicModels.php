<?php

namespace App\Console\Commands;

use App\Services\AI\AiModels;
use App\Services\AnthropicClient;
use Illuminate\Console\Command;

class VerifyAnthropicModels extends Command
{
    protected $signature = 'docintel:verify-models {--check-access : Check the provider Models API; no generation}';

    protected $description = 'Show effective model routing without printing credentials';

    public function handle(AiModels $models, AnthropicClient $client): int
    {
        $rows = [];
        $ok = true;
        $access = [];
        $approved = config('document_intelligence.approved_models', []);
        foreach (['extraction', 'entities', 'risks', 'deadlines', 'document_type', 'insights', 'context_resolution', 'ocr',
            'chart_vision', 'kpi_identity', 'document_summary', 'summary_repair', 'document_qa', 'document_comparison'] as $task) {
            $model = $models->forTask($task);
            if (! in_array($model, $approved, true)) {
                $ok = false;
            }
            if ($this->option('check-access') && ! array_key_exists($model, $access)) {
                try {
                    $access[$model] = $client->canAccessModel($model);
                } catch (\Throwable) {
                    $access[$model] = false;
                }
            }
            $available = $access[$model] ?? null;
            if ($available === false) {
                $ok = false;
            }
            $rows[] = [$task, $model, in_array($model, $approved, true) ? 'yes' : 'NO',
                $available === null ? 'not checked' : ($available ? 'available' : 'unavailable or request failed')];
        }
        $this->table(['Task', 'Configured model', 'Approved', 'Account access'], $rows);
        if (! $ok) {
            $this->error('A task resolves to a model outside document_intelligence.approved_models, or a model is inaccessible. Check ANTHROPIC_MODEL, ANTHROPIC_EXTRACTION_MODEL and ANTHROPIC_SYNTHESIS_MODEL.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
