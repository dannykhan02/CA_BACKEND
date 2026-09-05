<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable JSON blob — null means "user has never saved a
            // preference" (new accounts, or accounts created before this
            // column existed). The frontend already falls back to its own
            // defaults in that case, so this column intentionally has no
            // DB-level default.
            $table->json('notification_preferences')->nullable()->after('active');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notification_preferences');
        });
    }
};