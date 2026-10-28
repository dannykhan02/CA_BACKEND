<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A trial pocket kept apart from saved credits so it can expire. Only trials granted after AI_CREDITS_FREE_TRIAL_DAYS is set
 * use it; every existing balance stays where it is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workspace_credits', function (Blueprint $t) {
            $t->unsignedBigInteger('trial_credits_remaining')->default(0);
            $t->timestamp('trial_credits_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('workspace_credits', fn (Blueprint $t) => $t->dropColumn(['trial_credits_remaining', 'trial_credits_expires_at']));
    }
};
