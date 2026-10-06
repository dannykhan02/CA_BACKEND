<?php

namespace App\Console\Commands;

use App\Services\AI\ProviderGate;
use App\Support\QueueInspector;
use Illuminate\Console\Command;

/** Read-only, metadata-only: queue depth/age per pool and global Anthropic admission state. */
class QueueStatus extends Command
{
    protected $signature = 'docintel:queue-status {--json : Machine-readable output}';

    protected $description = 'Per-queue depth and oldest-job age, plus Anthropic in-flight permits and admission counters';

    public function handle(QueueInspector $inspector, ProviderGate $gate): int
    {
        $queues = $inspector->depths();
        $provider = $gate->snapshot();
        if ($this->option('json')) {
            $this->line(json_encode(['queues' => $queues, 'provider' => $provider], JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }
        if ($queues === []) {
            $this->warn('Queue backend is not Redis; depths unavailable.');
        } else {
            $this->table(['queue', 'ready', 'delayed', 'reserved', 'oldest ready age (s)'],
                collect($queues)->map(fn ($row, $name) => [$name, ...array_values($row)])->values()->all());
        }
        $this->table(['max in-flight', 'active permits', 'waiting documents', 'lease (s)', 'counters'], [[
            $provider['max_inflight'], $provider['active'], $provider['waiting_documents'], $provider['lease_seconds'],
            json_encode($provider['counters']),
        ]]);

        return self::SUCCESS;
    }
}
