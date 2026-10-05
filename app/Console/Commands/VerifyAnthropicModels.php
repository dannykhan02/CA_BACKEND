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
        foreach (['extraction', 'document_summary', 'context_resolution', 'ocr', 'chart_vision', 'document_qa', 'document_comparison', 'kpi_identity'] as $task) {
            $model = $models->forTask($task);
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
            $rows[] = [$task, $model, $available === null ? 'not checked' : ($available ? 'available' : 'unavailable or request failed')];
        }
        $this->table(['Task', 'Configured model', 'Account access'], $rows);

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
