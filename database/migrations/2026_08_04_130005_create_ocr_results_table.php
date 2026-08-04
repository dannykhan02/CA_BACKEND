<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-page OCR output, kept separate from the document's final extracted
 * text so you can compare engines, re-run OCR without losing history, and
 * distinguish "printed text OCR" from "handwriting/vision OCR" per page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ocr_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('page_number');
            $table->enum('engine', ['printed', 'handwriting', 'vision']);
            $table->longText('raw_text')->nullable();
            $table->decimal('confidence', 5, 4)->nullable(); // 0.0000–1.0000
            $table->json('metadata')->nullable(); // bounding boxes, language detected, etc.

            $table->timestamps();

            $table->index(['document_id', 'page_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ocr_results');
    }
};
