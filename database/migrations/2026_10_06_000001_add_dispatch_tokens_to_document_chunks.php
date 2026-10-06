<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            // Identifies the queue message that owns a queued unit. Recovery re-issues a unit
            // only when no message with this token is still in Redis (lost dispatch), and a
            // superseded message is dropped by token, so a backlog never multiplies messages.
            $table->uuid('dispatch_token')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            // Cross-document fairness check ("another document has queued extraction work").
            $table->index(['stage', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('document_chunks', function (Blueprint $table) {
            $table->dropIndex(['stage', 'status']);
            $table->dropColumn(['dispatch_token', 'dispatched_at']);
        });
    }
};
