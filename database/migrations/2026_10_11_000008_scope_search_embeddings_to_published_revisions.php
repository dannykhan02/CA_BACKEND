<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_embeddings', function (Blueprint $table): void {
            $table->foreignUuid('intelligence_revision_id')->nullable()
                ->constrained('document_intelligence_revisions')->restrictOnDelete();
            $table->foreign(['intelligence_revision_id', 'document_id', 'workspace_id'], 'embedding_revision_owner_fk')
                ->references(['id', 'document_id', 'workspace_id'])->on('document_intelligence_revisions')
                ->restrictOnDelete();
            $table->dropUnique('document_embeddings_document_id_chunk_index_unique');
            $table->unique(['intelligence_revision_id', 'chunk_index'], 'embedding_revision_chunk_unique');
        });
        DB::statement('CREATE UNIQUE INDEX embedding_legacy_chunk_unique ON document_embeddings (document_id, chunk_index) WHERE intelligence_revision_id IS NULL');
    }

    public function down(): void
    {
        if (DB::table('document_embeddings')->whereNotNull('intelligence_revision_id')->exists()) {
            throw new RuntimeException('Cannot roll back revision-owned search embeddings without losing history.');
        }
        DB::statement('DROP INDEX IF EXISTS embedding_legacy_chunk_unique');
        Schema::table('document_embeddings', function (Blueprint $table): void {
            $table->dropUnique('embedding_revision_chunk_unique');
            $table->dropForeign('embedding_revision_owner_fk');
            $table->dropConstrainedForeignId('intelligence_revision_id');
            $table->unique(['document_id', 'chunk_index']);
        });
    }
};
