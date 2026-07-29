<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * The existing `users_email_unique` constraint is case-sensitive, which
     * is how DKimaiyo@CA.GO.KE and dkimaiyo@ca.go.ke were able to coexist as
     * two separate rows. The application-level normalization on the User
     * model (App\Models\User::email()) now lowercases every write, but that
     * alone is racy under concurrent signups, so this adds a DB-level
     * safety net on lower(email).
     */
    public function up(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_email_unique');
            DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (lower(email))');
        } elseif ($driver === 'mysql') {
            // MySQL/MariaDB have no functional unique index on lower(email)
            // without a generated column, and collation is already
            // case-insensitive by default (utf8mb4_*_ci), so the existing
            // users_email_unique constraint already enforces this. Only
            // add the generated-column index if that's not the case.
            $collation = DB::selectOne(
                "SELECT COLLATION_NAME as collation FROM information_schema.COLUMNS
                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email'"
            );

            $isCaseInsensitive = $collation && str_contains(strtolower($collation->collation ?? ''), '_ci');

            if (! $isCaseInsensitive) {
                Schema::table('users', function ($table) {
                    $table->string('email_lower')->virtualAs('LOWER(email)')->nullable(false)->after('email');
                });
                DB::statement('ALTER TABLE users DROP INDEX IF EXISTS users_email_unique');
                DB::statement('CREATE UNIQUE INDEX users_email_lower_unique ON users (email_lower)');
            }
        } else {
            // sqlite and others: application-level normalization on the
            // User model is the primary safeguard here; no portable
            // functional-index syntax to add automatically.
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS users_email_lower_unique');
            DB::statement('ALTER TABLE users ADD CONSTRAINT users_email_unique UNIQUE (email)');
        } elseif ($driver === 'mysql') {
            if (Schema::hasColumn('users', 'email_lower')) {
                DB::statement('DROP INDEX IF EXISTS users_email_lower_unique ON users');
                Schema::table('users', function ($table) {
                    $table->dropColumn('email_lower');
                });
                DB::statement('CREATE UNIQUE INDEX users_email_unique ON users (email)');
            }
        }
    }
};