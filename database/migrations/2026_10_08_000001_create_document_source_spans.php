<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /*
         * Stable evidence spans for one extraction version of one document.
         *
         * Offsets only: the span's text is always retrieved from documents.extracted_text, so a
         * span row can never disagree with the source it points at, and a large document does not
         * store its own text twice. extraction_version fingerprints the extracted text and the
         * segmenter, so re-extraction creates a new span set instead of silently giving an existing
         * ID new meaning.
         */
        Schema::create('document_source_spans', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $t->string('extraction_version', 64);
            $t->string('span_key', 16);
            $t->unsignedInteger('ordinal');
            $t->unsignedInteger('page')->nullable();
            $t->unsignedInteger('start_offset');
            $t->unsignedInteger('end_offset');
            $t->string('type', 16);
            $t->timestamp('created_at')->nullable();
            $t->unique(['document_id', 'extraction_version', 'span_key']);
            $t->index(['document_id', 'extraction_version', 'ordinal']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_source_spans');
    }
};
