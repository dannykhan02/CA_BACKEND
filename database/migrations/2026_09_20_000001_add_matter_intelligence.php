<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_entities', function (Blueprint $t) {
            $t->index(['workspace_id', 'entity_type', 'normalized_value'], 'document_entities_related_lookup');
        });
        Schema::create('matters', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('name');
            $t->text('description')->nullable();
            $t->string('type')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'created_at']);
        });
        Schema::table('documents', function (Blueprint $t) {
            $t->foreignUuid('matter_id')->nullable()->constrained()->nullOnDelete();
            $t->index(['workspace_id', 'matter_id']);
        });
        Schema::create('document_relationships', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('from_document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignUuid('to_document_id')->constrained('documents')->cascadeOnDelete();
            $t->string('relationship_type', 80);
            $t->text('note')->nullable();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->timestamps();
            $t->unique(['from_document_id', 'to_document_id', 'relationship_type'], 'document_relationship_direction_unique');
            $t->index(['workspace_id', 'to_document_id']);
        });
        Schema::create('tracked_items', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('matter_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('source_key', 64);
            $t->json('source');
            $t->string('title');
            $t->string('type')->default('deadline');
            $t->date('due_date')->nullable();
            $t->enum('status', ['open', 'completed', 'dismissed'])->default('open');
            $t->text('notes')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->timestamp('remind_at')->nullable();
            $t->timestamp('reminded_at')->nullable();
            $t->timestamps();
            $t->unique(['document_id', 'source_key']);
            $t->index(['workspace_id', 'status', 'due_date']);
            $t->index(['status', 'remind_at']);
        });
        Schema::create('document_comparisons', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('matter_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignUuid('base_document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignUuid('compared_document_id')->constrained('documents')->cascadeOnDelete();
            $t->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $t->string('fingerprint', 64);
            $t->enum('status', ['queued', 'processing', 'completed', 'failed'])->default('queued');
            $t->text('summary')->nullable();
            $t->json('changes')->nullable();
            $t->json('metadata')->nullable();
            $t->text('error_message')->nullable();
            $t->timestamps();
            $t->unique(['workspace_id', 'base_document_id', 'compared_document_id', 'fingerprint'], 'comparison_snapshot_unique');
            $t->index(['workspace_id', 'matter_id', 'created_at']);
            $t->index('base_document_id');
            $t->index('compared_document_id');
        });
        Schema::create('document_suggestion_dismissals', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('related_document_id')->constrained('documents')->cascadeOnDelete();
            $t->timestamps();
            $t->unique(['user_id', 'document_id', 'related_document_id'], 'suggestion_dismissal_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_suggestion_dismissals');
        Schema::dropIfExists('document_comparisons');
        Schema::dropIfExists('tracked_items');
        Schema::dropIfExists('document_relationships');
        Schema::table('documents', function (Blueprint $t) {
            $t->dropIndex(['workspace_id', 'matter_id']);
            $t->dropConstrainedForeignId('matter_id');
        });
        Schema::dropIfExists('matters');
        Schema::table('document_entities', fn (Blueprint $t) => $t->dropIndex('document_entities_related_lookup'));
    }
};
