#!/usr/bin/env bash
#
# Adds new migrations on top of your existing (already-migrated) schema to
# make workspaces the ownership boundary and lay the processing-pipeline
# groundwork. Does NOT touch or remove any existing migration file.
#
# Usage:
#   cd /path/to/your/laravel/backend
#   bash add_workspace_centric_migrations.sh
#   php artisan migrate
#
set -euo pipefail

if [ ! -f "artisan" ]; then
  echo "Run this from your Laravel project root (artisan not found)."
  exit 1
fi

MIGDIR="database/migrations"
mkdir -p "$MIGDIR"

# ---------------------------------------------------------------------
# 1. Add workspace_id to the document-child tables
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_130000_add_workspace_id_to_document_children.php" << 'EOF'
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
EOF

# ---------------------------------------------------------------------
# 2. Backfill workspace_id on those tables from their parent document
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_130001_backfill_workspace_id_on_document_children.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // document_kpis, document_charts, document_page_flags all have
        // document_id directly.
        foreach (['document_kpis', 'document_charts', 'document_page_flags'] as $table) {
            DB::statement("
                UPDATE {$table} t
                SET workspace_id = d.workspace_id
                FROM documents d
                WHERE t.document_id = d.id
                  AND t.workspace_id IS NULL
            ");
        }

        // document_chart_points goes through document_charts.
        DB::statement("
            UPDATE document_chart_points p
            SET workspace_id = d.workspace_id
            FROM document_charts c
            JOIN documents d ON d.id = c.document_id
            WHERE p.document_chart_id = c.id
              AND p.workspace_id IS NULL
        ");
    }

    public function down(): void
    {
        // Deliberately irreversible — nulling workspace_id back out isn't
        // a meaningful inverse of a backfill.
    }
};
EOF

# ---------------------------------------------------------------------
# 3. workspace_settings
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_130002_create_workspace_settings_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Preferences live per-workspace, not per-user, so Organization workspaces
 * (v2) inherit the same shape without a redesign.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_settings', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->primary()->constrained()->cascadeOnDelete();

            $table->string('ai_provider')->default('anthropic');
            $table->string('theme')->default('system'); // 'light' | 'dark' | 'system'
            $table->string('language', 10)->default('en');
            $table->string('timezone')->default('UTC');

            $table->boolean('powerbi_enabled')->default(false);
            $table->boolean('ocr_enabled')->default(true);
            $table->boolean('handwriting_enabled')->default(false);

            $table->enum('default_classification', ['Public', 'Internal', 'Confidential', 'Restricted'])
                  ->default('Internal');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_settings');
    }
};
EOF

# ---------------------------------------------------------------------
# 4. workspace_ai_configs
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_130003_create_workspace_ai_configs_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces reading provider/model/keys from .env. In v1 there's one row
 * per workspace (usually one workspace per user); v2 organizations just
 * get their own row, no schema change needed.
 *
 * api_key_encrypted must ONLY ever be written/read through the model's
 * `encrypted` cast (App\Models\WorkspaceAiConfig -> protected $casts =
 * ['api_key_encrypted' => 'encrypted']) — never store or log the plaintext
 * key anywhere else.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_ai_configs', function (Blueprint $table) {
            $table->foreignUuid('workspace_id')->primary()->constrained()->cascadeOnDelete();

            $table->string('provider')->default('anthropic');
            $table->string('model')->default('claude-sonnet-4-6');
            $table->decimal('temperature', 3, 2)->default(0.20);
            $table->text('api_key_encrypted')->nullable();
            $table->unsignedInteger('max_tokens')->default(4096);
            $table->boolean('vision_enabled')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_ai_configs');
    }
};
EOF

# ---------------------------------------------------------------------
# 5. processing_jobs
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_130004_create_processing_jobs_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per pipeline stage per document, so the whole
 * upload -> scan -> detect -> ocr? -> extract -> normalize -> chunk ->
 * ai -> kpis/charts -> power_bi flow is inspectable and resumable, rather
 * than a single opaque "processing" boolean on documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();

            $table->enum('stage', [
                'virus_scan',
                'file_detection',
                'ocr_check',
                'extract',
                'normalize',
                'chunk',
                'ai_analysis',
                'kpis',
                'charts',
                'power_bi',
            ]);

            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'skipped'])
                  ->default('pending');

            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'stage']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_jobs');
    }
};
EOF

# ---------------------------------------------------------------------
# 6. ocr_results
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_130005_create_ocr_results_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-page OCR output, kept separate from the document's final extracted
 * text so you can compare engines, re-run OCR without losing history, and
 * distinguish "printed text OCR" from "handwriting/vision OCR" per page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('page_number');
            $table->enum('engine', ['printed', 'handwriting', 'vision']);
            $table->longText('raw_text')->nullable();
            $table->decimal('confidence', 5, 4)->nullable(); // 0.0000–1.0000
            $table->json('metadata')->nullable(); // bounding boxes, language detected, etc.

            $table->timestamps();

            $table->index(['document_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_results');
    }
};
EOF

# ---------------------------------------------------------------------
# 7. document_versions
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_130006_create_document_versions_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Re-uploads/reprocessing shouldn't silently overwrite the prior file —
 * keep history so a workspace can see what changed and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('version_number');
            $table->string('file_path');
            $table->string('file_hash', 64)->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('change_note')->nullable();

            $table->timestamps();

            $table->unique(['document_id', 'version_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_versions');
    }
};
EOF

# ---------------------------------------------------------------------
# 8. Seed workspace_settings + workspace_ai_configs for existing workspaces
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_130007_backfill_workspace_settings_and_ai_configs.php" << 'EOF'
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
EOF

echo
echo "Written to $MIGDIR:"
ls -1 "$MIGDIR" | grep 130
echo
echo "Next step:"
echo "  php artisan migrate"