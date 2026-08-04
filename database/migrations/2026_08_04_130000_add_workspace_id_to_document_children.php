<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Workspace becomes the ownership/query boundary, not just documents.
 * AI jobs (GenerateInsightsJob etc.) should be able to filter by
 * workspace_id directly without joining through documents every time.
 */
return new class extends Migration
{
    protected array $tables = [
        'document_kpis',
        'document_charts',
        'document_chart_points',
        'document_page_flags',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignUuid('workspace_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('workspaces')
                    ->nullOnDelete();

                $blueprint->index('workspace_id');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('workspace_id');
            });
        }
    }
};
