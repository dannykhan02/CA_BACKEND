<?php

namespace App\Console\Commands;

use App\Services\AI\ExtractionBenchmarkScorer;
use Illuminate\Console\Command;

class EvaluateExtractionRecordings extends Command
{
    protected $signature = 'docintel:evaluate-extraction {manifest : Local JSON with gold records and reviewed model recordings}';

    protected $description = 'Score recorded extraction responses offline; never calls a model';

    public function handle(ExtractionBenchmarkScorer $scorer): int
    {
        if (app()->environment('production')) {
            $this->error('Offline evaluation is restricted to development/staging.');

            return self::FAILURE;
        }
        $path = $this->argument('manifest');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('Unreadable evaluation manifest.');

            return self::FAILURE;
        }
        try {
            $manifest = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($manifest)) {
                throw new \InvalidArgumentException('Manifest must be a JSON object.');
            }
            $this->line(json_encode($scorer->score($manifest), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        } catch (\JsonException|\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
    }
}
