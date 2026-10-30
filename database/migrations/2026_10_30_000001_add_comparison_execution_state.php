<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_comparisons', function (Blueprint $table) {
            $table->uuid('dispatch_token')->nullable();
            $table->timestamp('dispatched_at')->nullable();
            $table->timestamp('provider_started_at')->nullable();
            $table->index(['status', 'dispatched_at'], 'comparison_recovery_lookup');
        });
    }

    public function down(): void
    {
        Schema::table('document_comparisons', function (Blueprint $table) {
            $table->dropIndex('comparison_recovery_lookup');
            $table->dropColumn(['dispatch_token', 'dispatched_at', 'provider_started_at']);
        });
    }
};
