<?php

namespace App\Observers;

use App\Models\Workspace;
use App\Models\WorkspaceSetting;

class WorkspaceObserver
{
    /**
     * Guarantee every workspace has a settings row from the moment it's
     * created. Prevents ExtractDocumentTextJob's null-safe
     * $document->workspace?->settings?->ocr_enabled from silently
     * resolving to false because the row simply doesn't exist yet —
     * that produced an "OCR disabled" failure for a workspace that was
     * never actually configured to have it off.
     *
     * Matches the defaults in 2026_08_04_130002_create_workspace_settings_table.
     * ocr_provider is left null (nullable column, no default) since it's
     * only meaningfully set once OCR is actually configured.
     */
    public function created(Workspace $workspace): void
    {
        WorkspaceSetting::firstOrCreate(
            ['workspace_id' => $workspace->id],
            [
                'ai_provider' => 'anthropic',
                'theme' => 'system',
                'language' => 'en',
                'timezone' => 'UTC',
                'powerbi_enabled' => false,
                'ocr_enabled' => true,
                'handwriting_enabled' => false,
                'default_classification' => 'Internal',
            ]
        );
    }
}
