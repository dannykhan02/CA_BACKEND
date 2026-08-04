<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per pipeline stage per document, so the whole
 * upload -> scan -> detect -> ocr? -> extract -> normalize -> chunk ->
 * ai -> kpis/charts -> power_bi flow is inspectable and resumable, rather
 * than a single opaque "processing" boolean on documents.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('processing_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();

            $table->enum('stage', [
                'virus_scan',
                'file_detection',
                'ocr_check',
                'extract',
                'normalize',
                'chunk',
                'ai_analysis',
                'kpis',
                'charts',
                'power_bi',
            ]);

            $table->enum('status', ['pending', 'processing', 'completed', 'failed', 'skipped'])
                  ->default('pending');

            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->text('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'stage']);
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('processing_jobs');
    }
};
