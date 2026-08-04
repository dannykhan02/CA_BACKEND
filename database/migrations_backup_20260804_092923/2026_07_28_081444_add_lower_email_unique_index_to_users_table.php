<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ensure emails are unique regardless of case.
     *
     * Example:
     * DKimaiyo@CA.GO.KE
     * dkimaiyo@ca.go.ke
     *
     * should be treated as the same email.
     */
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            CREATE UNIQUE INDEX IF NOT EXISTS users_email_lower_unique
            ON users (LOWER(email))
        ');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('
            DROP INDEX IF EXISTS users_email_lower_unique
        ');
    }
};