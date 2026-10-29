<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Additive: legacy content and source citations are never rewritten.
        Schema::create('document_note_references', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('note_id');
            $table->uuid('document_id');
            // Retain the original identity even when revision evidence is pruned.
            $table->uuid('intelligence_revision_id');
            $table->string('reference_type', 32);
            $table->string('finding_id', 200);
            $table->string('label', 200);
            $table->string('source_id', 200)->nullable();
            $table->smallInteger('page')->nullable();
            $table->text('quote_snapshot')->nullable();
            $table->timestamps();
            $table->foreign(['note_id', 'document_id'], 'note_references_owner_fk')
                ->references(['id', 'document_id'])->on('document_notes')->cascadeOnDelete();
            $table->unique(['note_id', 'intelligence_revision_id', 'reference_type', 'finding_id'], 'note_reference_identity');
            $table->index(['document_id', 'intelligence_revision_id', 'finding_id'], 'note_reference_reverse');
        });
    }

    public function down(): void
    {
        // Only the newly introduced relationships are removed on rollback.
        Schema::dropIfExists('document_note_references');
    }
};
