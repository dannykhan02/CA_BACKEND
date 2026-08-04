<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Disabled so a failure on any one statement doesn't get masked by
    // Postgres's generic "current transaction is aborted" cascade error
    // on every statement after it — makes real failures visible with -v.
    public $withinTransaction = false;

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

            // Referenced by add_workspace_columns (->after('role')) and by
            // the legacy-workspace backfill ($user->role). Was missing from
            // the original create_users_table migration.
            $table->string('role')->default('Owner');

            $table->boolean('active')->default(true);
            $table->timestamp('last_active_at')->nullable();

            $table->uuid('current_workspace_id')->nullable();

            $table->rememberToken();
            $table->timestamps();

            $table->index('active');
            $table->index('role');
        });

        $this->applyCaseInsensitiveEmail();
    }

    /**
     * Case-insensitive email uniqueness at the DB layer (collapses
     * DKimaiyo@CA.GO.KE and dkimaiyo@ca.go.ke into one row), not just
     * app-level normalization on the User model.
     *
     * Guarded with IF EXISTS / IF NOT EXISTS throughout so re-running this
     * migration against a DB left in a half-finished state (e.g. an earlier
     * failed migrate:fresh) never errors out on "already exists" /
     * "does not exist" — it converges to the same end state either way.
     */
    protected function applyCaseInsensitiveEmail(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
            DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
            DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
            return;
        }

        if ($driver === 'mysql') {
            $collation = DB::selectOne(
                "SELECT COLLATION_NAME as collation FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'"
            );
            $isCaseInsensitive = $collation && str_contains(strtolower($collation->collation ?? ''), '_ci');

            if ($isCaseInsensitive) {
                return; // utf8mb4_*_ci already enforces this via users_email_unique
            }

            if (! Schema::hasColumn('users', 'email_lower')) {
                Schema::table('users', function (Blueprint $table) {
                    $table->string('email_lower')->virtualAs('LOWER(email)')->nullable(false)->after('email');
                });
            }

            DB::statement('ALTER TABLE users DROP INDEX IF EXISTS users_email_unique');
            DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique ON users (email_lower)');
            return;
        }

        // sqlite and others: app-level normalization on the User model
        // (App\Models\User::email()) is the safeguard — no portable
        // functional-index syntax to add automatically.
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};