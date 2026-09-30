<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('billing_webhook_events', function (Blueprint $table) {
            $table->string('status')->default('received')->index();
            $table->string('deferral_reason')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('requires_review_at')->nullable();
        });
        // Historical rows retain their original processed_at proof.
        \Illuminate\Support\Facades\DB::table('billing_webhook_events')->whereNotNull('processed_at')->update(['status' => 'processed']);
        \Illuminate\Support\Facades\DB::table('billing_webhook_events')->whereNull('processed_at')->update(['status' => 'deferred', 'deferral_reason' => 'historical_unresolved']);
    }

    public function down(): void
    {
        Schema::table('billing_webhook_events', fn (Blueprint $table) => $table->dropColumn([
            'status', 'deferral_reason', 'retry_count', 'last_attempted_at', 'requires_review_at',
        ]));
    }
};
