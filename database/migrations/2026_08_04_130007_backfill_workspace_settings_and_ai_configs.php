<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $workspaceIds = DB::table('workspaces')->pluck('id');

        foreach ($workspaceIds as $workspaceId) {
            DB::table('workspace_settings')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'ai_provider' => 'anthropic',
                'theme' => 'system',
                'language' => 'en',
                'timezone' => 'UTC',
                'powerbi_enabled' => false,
                'ocr_enabled' => true,
                'handwriting_enabled' => false,
                'default_classification' => 'Internal',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('workspace_ai_configs')->insertOrIgnore([
                'workspace_id' => $workspaceId,
                'provider' => 'anthropic',
                'model' => 'claude-sonnet-4-6',
                'temperature' => 0.20,
                'api_key_encrypted' => null,
                'max_tokens' => 4096,
                'vision_enabled' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Deliberately irreversible.
    }
};
