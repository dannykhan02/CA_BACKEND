<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use App\Models\WorkspaceSetting;
use Illuminate\Console\Command;

class BackfillWorkspaceSettings extends Command
{
    protected $signature = 'workspaces:backfill-settings {--dry-run}';
    protected $description = 'Create missing workspace_settings rows for any workspace that lacks one (covers anything created after the one-time 2026_08_04 backfill migration)';

    public function handle(): int
    {
        $missing = Workspace::doesntHave('settings')->get();

        if ($missing->isEmpty()) {
            $this->info('No workspaces missing settings.');
            return self::SUCCESS;
        }

        $this->warn("Found {$missing->count()} workspace(s) missing a settings row:");
        foreach ($missing as $workspace) {
            $this->line(" - {$workspace->id} ({$workspace->name})");
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run — no changes made.');
            return self::SUCCESS;
        }

        foreach ($missing as $workspace) {
            WorkspaceSetting::create([
                'workspace_id' => $workspace->id,
                'ai_provider' => 'anthropic',
                'theme' => 'system',
                'language' => 'en',
                'timezone' => 'UTC',
                'powerbi_enabled' => false,
                'ocr_enabled' => true,
                'handwriting_enabled' => false,
                'default_classification' => 'Internal',
            ]);
        }

        $this->info("Backfilled settings for {$missing->count()} workspace(s).");
        return self::SUCCESS;
    }
}
