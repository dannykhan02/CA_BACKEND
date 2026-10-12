<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('processing_jobs', function (Blueprint $table): void {
            $table->foreignUuid('intelligence_revision_id')->nullable()
                ->constrained('document_intelligence_revisions')->restrictOnDelete();
            $table->index(['intelligence_revision_id', 'stage', 'created_at'], 'processing_revision_stage_idx');
            $table->foreign(['intelligence_revision_id', 'document_id', 'workspace_id'], 'processing_revision_owner_fk')
                ->references(['id', 'document_id', 'workspace_id'])->on('document_intelligence_revisions')
                ->restrictOnDelete();
        });
        Schema::table('ocr_results', function (Blueprint $table): void {
            $table->foreignUuid('intelligence_revision_id')->nullable()
                ->constrained('document_intelligence_revisions')->cascadeOnDelete();
            $table->unique(['intelligence_revision_id', 'page_number'], 'ocr_revision_page_unique');
            $table->foreign(['intelligence_revision_id', 'document_id', 'workspace_id'], 'ocr_revision_owner_fk')
                ->references(['id', 'document_id', 'workspace_id'])->on('document_intelligence_revisions')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('ocr_results', function (Blueprint $table): void {
            $table->dropForeign('ocr_revision_owner_fk');
            $table->dropUnique('ocr_revision_page_unique');
            $table->dropConstrainedForeignId('intelligence_revision_id');
        });
        Schema::table('processing_jobs', function (Blueprint $table): void {
            $table->dropForeign('processing_revision_owner_fk');
            $table->dropIndex('processing_revision_stage_idx');
            $table->dropConstrainedForeignId('intelligence_revision_id');
        });
    }
};
