<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_ai_runs', function (Blueprint $table) {
            $table->timestampTz('provider_started_at', 3)->nullable();
            $table->timestampTz('provider_finished_at', 3)->nullable();
            $table->string('timeout_source', 40)->nullable();
            $table->boolean('provider_response_received')->nullable();
            $table->unsignedInteger('input_tokens_counted')->nullable();
            $table->timestampTz('dispatched_at', 3)->nullable();
            $table->timestampTz('worker_started_at', 3)->nullable();
            $table->timestampTz('admission_requested_at', 3)->nullable();
            $table->timestampTz('lease_acquired_at', 3)->nullable();
            $table->timestampTz('lease_released_at', 3)->nullable();
            $table->unsignedInteger('worker_wait_ms')->nullable();
            $table->unsignedInteger('fairness_wait_ms')->nullable();
            $table->unsignedInteger('admission_wait_ms')->nullable();
            $table->string('dispatch_reason', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_ai_runs', fn (Blueprint $table) => $table->dropColumn([
            'provider_started_at', 'provider_finished_at', 'timeout_source',
            'provider_response_received', 'input_tokens_counted',
            'dispatched_at', 'worker_started_at', 'admission_requested_at', 'lease_acquired_at',
            'lease_released_at', 'worker_wait_ms', 'fairness_wait_ms', 'admission_wait_ms', 'dispatch_reason',
        ]));
    }
};
