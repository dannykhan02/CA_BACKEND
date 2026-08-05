<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * workspace_id denormalized directly onto this table (not just reachable
 * via document_id join) — matches the pattern already used on
 * document_kpis/charts/page_flags/ocr_results/processing_jobs: the tenant
 * boundary is enforceable at every table, not just at documents, and it
 * keeps every search query's WHERE clause a single indexed column instead
 * of a join.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_embeddings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();

            $table->unsignedInteger('chunk_index');
            $table->text('chunk_text');
            $table->string('provider')->default('voyage');
            $table->string('model');

            $table->timestamps();

            $table->unique(['document_id', 'chunk_index']);
            $table->index('workspace_id');
        });

        // pgvector's own column type — no native Blueprint helper for this
        // in Laravel, so raw SQL. Voyage's voyage-3 model outputs 1024 dims;
        // confirm against current Voyage docs before relying on this number,
        // their model lineup changes.
        DB::statement('ALTER TABLE document_embeddings ADD COLUMN embedding vector(1024)');

        // IVFFlat needs data present to build meaningful clusters — for a
        // fresh table this creates an index with default/empty clustering
        // that Postgres will use regardless, but running `REINDEX` after
        // you have a few thousand rows will produce much better recall.
        DB::statement('CREATE INDEX document_embeddings_embedding_idx ON document_embeddings USING ivfflat (embedding vector_cosine_ops) WITH (lists = 100)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_embeddings');
    }
};