<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', fn (Blueprint $t) => $t->jsonb('ai_pipeline')->nullable());
        Schema::create('document_chunks', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $t->uuid('parent_id')->nullable();
            $t->string('pipeline_key', 64);
            $t->string('identity', 100);
            $t->string('stage', 24)->default('extraction');
            $t->string('input_hash', 64);
            $t->string('pipeline_version', 32);
            $t->string('prompt_version', 32);
            $t->unsignedInteger('start_offset')->default(0);
            $t->unsignedInteger('end_offset')->default(0);
            $t->unsignedInteger('start_page')->nullable();
            $t->unsignedInteger('end_page')->nullable();
            $t->unsignedInteger('token_count')->default(0);
            $t->unsignedInteger('overlap_chars')->default(0);
            $t->unsignedSmallInteger('depth')->default(0);
            $t->string('status', 24)->default('pending');
            $t->unsignedSmallInteger('attempts')->default(0);
            $t->string('failure_class', 40)->nullable();
            $t->decimal('reserved_cost', 12, 6)->default(0);
            $t->jsonb('result')->nullable();
            $t->timestamp('started_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamps();
            $t->unique(['document_id', 'pipeline_key', 'identity']);
            $t->index(['document_id', 'pipeline_key', 'status']);
        });
        Schema::table('document_chunks', fn (Blueprint $t) => $t->foreign('parent_id')->references('id')->on('document_chunks')->cascadeOnDelete());
        Schema::create('document_evidence', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $t->string('pipeline_key', 64);
            $t->string('identity', 64);
            $t->string('kind', 24);
            $t->string('source_id')->nullable();
            $t->jsonb('data');
            $t->jsonb('sources');
            $t->timestamps();
            $t->unique(['document_id', 'pipeline_key', 'identity']);
            $t->index(['workspace_id', 'document_id', 'pipeline_key']);
        });
        Schema::table('document_ai_runs', function (Blueprint $t) {
            $t->uuid('chunk_id')->nullable()->index();
            $t->string('pipeline_version', 32)->nullable();
            $t->unsignedSmallInteger('request_attempt')->default(1);
            $t->unsignedInteger('cache_creation_tokens')->default(0);
            $t->unsignedInteger('cache_read_tokens')->default(0);
            $t->unsignedInteger('duration_ms')->nullable();
            $t->unsignedBigInteger('process_peak_memory_bytes')->nullable();
            $t->decimal('estimated_cost_usd', 12, 6)->nullable();
            $t->string('failure_class', 40)->nullable();
            $t->string('provider_request_id')->nullable();
            $t->boolean('partial')->default(false);
            $t->boolean('evidence_trimmed')->default(false);
            $t->unsignedInteger('optional_items_dropped')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('document_ai_runs', fn (Blueprint $t) => $t->dropColumn([
            'chunk_id', 'pipeline_version', 'request_attempt', 'cache_creation_tokens', 'cache_read_tokens',
            'duration_ms', 'process_peak_memory_bytes', 'estimated_cost_usd', 'failure_class', 'provider_request_id', 'partial',
            'evidence_trimmed', 'optional_items_dropped',
        ]));
        Schema::dropIfExists('document_evidence');
        Schema::dropIfExists('document_chunks');
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('ai_pipeline'));
    }
};
