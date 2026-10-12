<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_intelligence_revisions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('generation');
            $table->string('state', 32)->default('candidate');
            $table->string('file_hash', 64)->nullable();
            $table->string('extracted_text_hash', 64)->nullable();
            $table->longText('extracted_text')->nullable();
            $table->string('parser_version')->nullable();
            $table->string('structure_policy')->nullable();
            $table->string('pipeline_key', 64)->nullable();
            $table->string('provenance_policy')->nullable();
            $table->uuid('structured_extraction_id')->nullable();
            $table->uuid('provenance_set_id')->nullable();
            $table->json('ai_pipeline')->nullable();
            $table->json('insights')->nullable();
            $table->boolean('has_structured_data')->default(false);
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();
            $table->unique(['document_id', 'generation']);
            $table->unique(['id', 'document_id', 'workspace_id'], 'intelligence_revision_owner_unique');
            $table->index(['document_id', 'state']);
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->unsignedBigInteger('intelligence_generation')->default(0);
            $table->uuid('active_intelligence_revision_id')->nullable();
            $table->uuid('published_intelligence_revision_id')->nullable();
            $table->foreign('active_intelligence_revision_id', 'documents_active_intelligence_revision_fk')
                ->references('id')->on('document_intelligence_revisions')->nullOnDelete();
            $table->foreign('published_intelligence_revision_id', 'documents_published_intelligence_revision_fk')
                ->references('id')->on('document_intelligence_revisions')->nullOnDelete();
        });

        foreach (['document_kpis', 'document_charts', 'document_entities', 'document_risks',
            'document_deadlines', 'document_type_classifications', 'document_intelligence_summaries'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->uuid('intelligence_revision_id')->nullable();
                $table->index(['document_id', 'intelligence_revision_id'], $name.'_intelligence_revision_idx');
                $table->foreign('intelligence_revision_id', $name.'_intelligence_revision_fk')
                    ->references('id')->on('document_intelligence_revisions')->restrictOnDelete();
            });
        }

        foreach (['document_type_classifications', 'document_intelligence_summaries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropUnique($name.'_document_id_unique'));
            DB::statement("CREATE UNIQUE INDEX {$name}_legacy_unique ON {$name} (document_id) WHERE intelligence_revision_id IS NULL");
            Schema::table($name, fn (Blueprint $table) => $table->unique(
                ['document_id', 'intelligence_revision_id'], $name.'_revision_unique'));
        }
    }

    public function down(): void
    {
        foreach (['document_type_classifications', 'document_intelligence_summaries'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropUnique($name.'_revision_unique'));
            DB::statement("DROP INDEX IF EXISTS {$name}_legacy_unique");
            // Historical revisions may contain multiple rows for one document.
            // Refuse destructive rollback until revision-owned rows are explicitly retained/exported.
            if (DB::table($name)->whereNotNull('intelligence_revision_id')->exists()) {
                throw new RuntimeException("Cannot roll back intelligence revisions while {$name} has revision-owned rows.");
            }
            Schema::table($name, fn (Blueprint $table) => $table->unique('document_id'));
        }
        foreach (['document_kpis', 'document_charts', 'document_entities', 'document_risks',
            'document_deadlines', 'document_type_classifications', 'document_intelligence_summaries'] as $name) {
            Schema::table($name, function (Blueprint $table) use ($name): void {
                $table->dropForeign($name.'_intelligence_revision_fk');
                $table->dropIndex($name.'_intelligence_revision_idx');
                $table->dropColumn('intelligence_revision_id');
            });
        }
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropForeign('documents_active_intelligence_revision_fk');
            $table->dropForeign('documents_published_intelligence_revision_fk');
            $table->dropColumn(['intelligence_generation', 'active_intelligence_revision_id',
                'published_intelligence_revision_id']);
        });
        Schema::dropIfExists('document_intelligence_revisions');
    }
};
