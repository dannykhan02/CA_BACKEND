#!/usr/bin/env bash
#
# Squashes every migration in database/migrations/ into a clean,
# consolidated set that reflects the FINAL schema you're actually running
# (workspaces + workspace_members design, not the older workspace_users
# draft from the advice doc). Safe only because you're pre-production and
# willing to `migrate:fresh`. DO NOT run this against a deployed/shared DB.
#
# Usage:
#   cd /path/to/your/laravel/backend
#   bash squash_migrations.sh
#
set -euo pipefail

if [ ! -f "artisan" ]; then
  echo "Run this from your Laravel project root (artisan not found)."
  exit 1
fi

MIGDIR="database/migrations"
BACKUP="database/migrations_backup_$(date +%Y%m%d_%H%M%S)"

echo "Backing up existing migrations to $BACKUP ..."
cp -r "$MIGDIR" "$BACKUP"

echo "Removing existing migrations ..."
rm -f "$MIGDIR"/*.php

mkdir -p "$MIGDIR"

# ---------------------------------------------------------------------
# 0001_01_01_000000_create_users_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/0001_01_01_000000_create_users_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->string('full_name');
            $table->string('email')->unique();
            $table->string('pending_email')->nullable();
            $table->string('pending_email_code', 255)->nullable();
            $table->timestamp('pending_email_expires_at')->nullable();

            $table->string('password');
            $table->timestamp('email_verified_at')->nullable();

            // Restored: referenced by add_workspace_columns (->after('role'))
            // and by the legacy-workspace backfill ($user->role). Was
            // missing from the original create_users_table migration.
            $table->string('role')->default('Owner');

            $table->boolean('active')->default(true);
            $table->timestamp('last_active_at')->nullable();

            $table->uuid('current_workspace_id')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('active');
            $table->index('role');
        });

        // Case-insensitive email uniqueness (collapses DKimaiyo@CA.GO.KE
        // and dkimaiyo@ca.go.ke into one row at the DB layer, not just
        // app-level normalization).
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
            DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        } elseif ($driver === 'mysql') {
            $collation = DB::selectOne(
                "SELECT COLLATION_NAME as collation FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'"
            );
            $isCaseInsensitive = $collation && str_contains(strtolower($collation->collation ?? ''), '_ci');

            if (! $isCaseInsensitive) {
                Schema::table('users', function (Blueprint $table) {
                    $table->string('email_lower')->virtualAs('LOWER(email)')->nullable(false)->after('email');
                });
                DB::statement('ALTER TABLE users DROP INDEX users_email_unique');
                DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (email_lower)');
            }
        }
        // sqlite: app-level normalization on the User model is the safeguard.
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
EOF

# ---------------------------------------------------------------------
# 0001_01_01_000001_create_cache_table.php  (unchanged)
# ---------------------------------------------------------------------
cat > "$MIGDIR/0001_01_01_000001_create_cache_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
EOF

# ---------------------------------------------------------------------
# 0001_01_01_000002_create_jobs_table.php  (unchanged)
# ---------------------------------------------------------------------
cat > "$MIGDIR/0001_01_01_000002_create_jobs_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedSmallInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('connection');
            $table->string('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();

            $table->index(['connection', 'queue', 'failed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000001_create_workspaces_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000001_create_workspaces_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type'); // 'Personal' | 'Organization'
            $table->string('name')->nullable(); // null for Personal
            $table->timestamps();
        });

        DB::statement(
            "ALTER TABLE workspaces ADD CONSTRAINT workspaces_type_check CHECK (type IN ('Personal','Organization'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('workspaces');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000002_create_workspace_members_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000002_create_workspace_members_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // Null in Personal workspaces — one member, no role hierarchy.
            $table->string('role')->nullable();
            $table->timestamp('joined_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_members');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000003_add_current_workspace_fk_to_users_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000003_add_current_workspace_fk_to_users_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreign('current_workspace_id')
                ->references('id')->on('workspaces')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['current_workspace_id']);
        });
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000004_create_verification_codes_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000004_create_verification_codes_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('verification_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            // Widened up front for Hash::make() output (bcrypt ~60 chars),
            // instead of a later "widen_otp_columns_for_hashing" patch.
            $table->string('code', 255);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['user_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('verification_codes');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000005_create_documents_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000005_create_documents_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->enum('type', ['PDF', 'DOCX']);
            $table->unsignedInteger('size_kb');
            $table->enum('status', ['Processing', 'Ready', 'Needs Review', 'Failed'])
                  ->default('Processing');
            $table->enum('classification', ['Public', 'Internal', 'Confidential', 'Restricted']);
            $table->unsignedSmallInteger('year');

            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('last_updated_by')->nullable()->constrained('users');
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();

            $table->unsignedInteger('pages')->default(0);
            $table->boolean('has_structured_data')->default(false);
            $table->unsignedTinyInteger('progress')->nullable();
            $table->text('error_message')->nullable();
            $table->enum('power_bi_status', ['synced', 'not-synced', 'failed'])
                  ->default('not-synced');
            $table->json('insights')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_hash', 64)->nullable();

            $table->unsignedTinyInteger('extraction_attempts')->default(0);
            $table->timestamp('extraction_started_at')->nullable();
            $table->timestamp('extraction_completed_at')->nullable();
            // Raw model token usage, kept for cost auditing — not exposed via API.
            $table->unsignedInteger('extraction_input_tokens')->nullable();
            $table->unsignedInteger('extraction_output_tokens')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('classification');
            $table->index('year');
            $table->index('uploaded_by');
            $table->index('file_hash');
        });

        DB::statement(
            "ALTER TABLE documents ADD CONSTRAINT documents_classification_check CHECK (classification IN ('Public','Internal','Confidential','Restricted'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000006_create_document_kpis_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000006_create_document_kpis_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_kpis', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->string('value');
            // Parsed numeric form of `value` (e.g. "64.2M" -> 64200000).
            // Nullable: some AI-written values genuinely aren't numeric —
            // treat null as "not chartable", not zero.
            $table->decimal('value_numeric', 20, 4)->nullable();
            $table->string('unit')->nullable();
            $table->enum('trend', ['up', 'down', 'flat'])->nullable();
            $table->string('trend_value')->nullable();
            $table->timestamps();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_kpis');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000007_create_document_charts_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000007_create_document_charts_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_charts', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->enum('type', ['bar', 'line', 'pie', 'table']);
            $table->string('title');
            $table->text('description');
            $table->json('data');
            $table->timestamps();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_charts');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000008_create_document_chart_points_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000008_create_document_chart_points_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_chart_points', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_chart_id')->constrained('document_charts')->cascadeOnDelete();
            $table->string('label');
            $table->decimal('value', 20, 4);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('document_chart_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chart_points');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000009_create_document_page_flags_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000009_create_document_page_flags_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_page_flags', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('page');
            $table->enum('status', ['parsed', 'partial', 'failed']);
            $table->text('note');
            $table->timestamps();

            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_page_flags');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000010_create_personal_access_tokens_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000010_create_personal_access_tokens_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->uuidMorphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('personal_access_tokens');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000011_create_password_reset_tokens_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000011_create_password_reset_tokens_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000012_create_audit_logs_table.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000012_create_audit_logs_table.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Append-only audit trail for who viewed/downloaded/approved/rejected/
 * reprocessed a document. Table-stakes for a compliance investigation
 * ("who accessed this Restricted filing, and when").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();
            $table->string('action'); // e.g. 'document.view', 'document.download', 'document.approve'
            $table->string('auditable_type');
            $table->uuid('auditable_id');
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['auditable_type', 'auditable_id']);
            $table->index(['user_id', 'created_at']);
            $table->index('action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000013_create_power_bi_reporting_views.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000013_create_power_bi_reporting_views.php" << 'EOF'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Power BI never touches live app tables directly — only these two views.
 * Both hard-exclude Restricted documents regardless of any future
 * auto_extract_classifications config change, since a BI dashboard is a
 * much broader-audience surface than the app's own classification policy.
 * See docs/DECISIONS.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE VIEW power_bi_kpis AS
            SELECT
                d.id AS document_id,
                d.name AS document_name,
                d.classification,
                d.year,
                d.created_at AS document_uploaded_at,
                k.label,
                k.value AS value_display,
                k.value_numeric,
                k.unit,
                k.trend,
                k.trend_value
            FROM document_kpis k
            JOIN documents d ON d.id = k.document_id
            WHERE d.classification != 'Restricted'
              AND d.status = 'Ready'
        ");

        DB::statement("
            CREATE VIEW power_bi_chart_points AS
            SELECT
                d.id AS document_id,
                d.name AS document_name,
                d.classification,
                d.year,
                c.id AS chart_id,
                c.type AS chart_type,
                c.title AS chart_title,
                p.label,
                p.value,
                p.sort_order
            FROM document_chart_points p
            JOIN document_charts c ON c.id = p.document_chart_id
            JOIN documents d ON d.id = c.document_id
            WHERE d.classification != 'Restricted'
              AND d.status = 'Ready'
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS power_bi_kpis');
        DB::statement('DROP VIEW IF EXISTS power_bi_chart_points');
    }
};
EOF

# ---------------------------------------------------------------------
# 2026_08_04_000014_backfill_legacy_workspace.php
# ---------------------------------------------------------------------
cat > "$MIGDIR/2026_08_04_000014_backfill_legacy_workspace.php" << 'EOF'
<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function () {
            if (User::count() === 0) {
                return; // fresh install — nothing to backfill
            }

            $legacyWorkspaceId = (string) Str::uuid();

            DB::table('workspaces')->insert([
                'id' => $legacyWorkspaceId,
                'type' => 'Organization',
                'name' => 'Legacy Organization',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            User::query()->chunkById(200, function ($users) use ($legacyWorkspaceId) {
                foreach ($users as $user) {
                    DB::table('workspace_members')->insert([
                        'id' => (string) Str::uuid(),
                        'workspace_id' => $legacyWorkspaceId,
                        'user_id' => $user->id,
                        'role' => $user->role,
                        'joined_at' => now(),
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            });

            DB::table('users')->update(['current_workspace_id' => $legacyWorkspaceId]);
            DB::table('documents')->whereNull('workspace_id')->update(['workspace_id' => $legacyWorkspaceId]);
        });
    }

    public function down(): void
    {
        // Deliberately irreversible.
    }
};
EOF

echo
echo "Done. New migrations written to $MIGDIR:"
ls -1 "$MIGDIR"
echo
echo "Old migrations backed up to: $BACKUP"
echo
echo "Next step (this WIPES your local DB):"
echo "  php artisan migrate:fresh"