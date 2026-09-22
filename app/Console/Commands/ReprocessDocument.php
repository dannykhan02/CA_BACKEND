<?php

namespace App\Console\Commands;

use App\Models\Document;
use App\Models\User;
use App\Services\Documents\DocumentReprocessor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Gate;

class ReprocessDocument extends Command
{
    protected $signature = 'documents:reprocess {document} {--actor= : Authorized user ID recorded as the actor}';

    protected $description = 'Queue recovery for one Failed or Needs Review document using the API recovery workflow';

    public function handle(DocumentReprocessor $reprocessor): int
    {
        if (! $this->option('actor')) {
            $this->error('Supply --actor=<user-id>; recovery requires an explicit authorized actor.');

            return self::FAILURE;
        }
        $document = Document::findOrFail($this->argument('document'));
        $actor = User::findOrFail($this->option('actor'));
        Gate::forUser($actor)->authorize('reprocess', $document);
        $reprocessor->reprocess($document, $actor);
        $this->info("Recovery queued for {$document->id}; verify processing_jobs and final status before calling it successful.");

        return self::SUCCESS;
    }
}
