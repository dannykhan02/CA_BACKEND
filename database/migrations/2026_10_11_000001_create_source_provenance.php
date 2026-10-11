<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_evidence', fn (Blueprint $t) => $t->unique(['id', 'workspace_id', 'document_id'], 'document_evidence_owner_unique'));
        Schema::create('source_provenance_sets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $t->string('source_revision', 64);
            $t->string('parser_version', 64);
            $t->string('policy_version', 32);
            $t->string('graph_hash', 64);
            $t->string('pipeline_key', 64);
            $t->boolean('published')->default(false);
            $t->boolean('active')->default(false);
            $t->timestamps();
            $t->unique(['document_id', 'source_revision', 'parser_version', 'policy_version', 'pipeline_key'], 'source_provenance_revision_unique');
            $t->unique(['id', 'workspace_id', 'document_id'], 'source_provenance_owner_unique');
            $t->index(['workspace_id', 'document_id', 'published']);
        });

        Schema::create('source_anchors', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('set_id');
            $t->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $t->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $t->string('source_key', 200);
            $t->string('kind', 32);
            $t->string('modality', 32);
            $t->unsignedInteger('page')->nullable();
            $t->string('sheet', 128)->nullable();
            $t->string('structural_path', 250)->nullable();
            $t->unsignedInteger('start_offset')->nullable();
            $t->unsignedInteger('end_offset')->nullable();
            $t->text('source_text')->nullable();
            $t->string('source_hash', 64)->nullable();
            $t->jsonb('geometry')->nullable();
            $t->timestamps();
            $t->foreign(['set_id', 'workspace_id', 'document_id'], 'source_anchor_set_owner')
                ->references(['id', 'workspace_id', 'document_id'])->on('source_provenance_sets')->cascadeOnDelete();
            $t->unique(['set_id', 'source_key']);
            $t->unique(['id', 'set_id', 'workspace_id', 'document_id'], 'source_anchor_owner_unique');
            $t->index(['set_id', 'kind']);
        });

        Schema::create('source_relationships', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('set_id');
            $t->uuid('workspace_id');
            $t->uuid('document_id');
            $t->uuid('from_anchor_id');
            $t->uuid('to_anchor_id');
            $t->string('type', 32);
            $t->unsignedSmallInteger('path_order')->default(0);
            $t->foreign(['from_anchor_id', 'set_id', 'workspace_id', 'document_id'], 'source_relation_from_owner')
                ->references(['id', 'set_id', 'workspace_id', 'document_id'])->on('source_anchors')->cascadeOnDelete();
            $t->foreign(['to_anchor_id', 'set_id', 'workspace_id', 'document_id'], 'source_relation_to_owner')
                ->references(['id', 'set_id', 'workspace_id', 'document_id'])->on('source_anchors')->cascadeOnDelete();
            $t->unique(['set_id', 'from_anchor_id', 'to_anchor_id', 'type'], 'source_relation_unique');
            $t->index(['set_id', 'from_anchor_id', 'type']);
        });

        Schema::create('evidence_role_bindings', function (Blueprint $t) {
            $t->bigIncrements('id');
            $t->uuid('set_id');
            $t->uuid('workspace_id');
            $t->uuid('document_id');
            $t->uuid('evidence_id');
            $t->uuid('anchor_id');
            $t->string('role', 32);
            $t->foreign(['anchor_id', 'set_id', 'workspace_id', 'document_id'], 'evidence_role_anchor_owner')
                ->references(['id', 'set_id', 'workspace_id', 'document_id'])->on('source_anchors')->cascadeOnDelete();
            $t->foreign(['evidence_id', 'workspace_id', 'document_id'], 'evidence_role_evidence_owner')
                ->references(['id', 'workspace_id', 'document_id'])->on('document_evidence')->cascadeOnDelete();
            $t->unique(['set_id', 'evidence_id', 'anchor_id', 'role'], 'evidence_role_unique');
            $t->index(['workspace_id', 'document_id', 'evidence_id']);
        });
        DB::statement('ALTER TABLE source_anchors ADD CONSTRAINT source_anchor_offset_check CHECK ((start_offset IS NULL AND end_offset IS NULL) OR (start_offset IS NOT NULL AND end_offset IS NOT NULL AND end_offset > start_offset))');
        DB::statement('CREATE UNIQUE INDEX source_provenance_one_active ON source_provenance_sets (document_id, pipeline_key) WHERE active');
        DB::statement('ALTER TABLE source_anchors ADD CONSTRAINT source_anchor_geometry_check CHECK (geometry IS NULL OR page IS NOT NULL)');
        DB::statement("ALTER TABLE source_anchors ADD CONSTRAINT source_anchor_kind_check CHECK (kind IN ('text_span', 'paragraph', 'table', 'cell', 'row_header', 'column_header', 'section_heading', 'caption', 'footnote', 'worksheet_cell', 'ocr_region'))");
        DB::statement("ALTER TABLE source_anchors ADD CONSTRAINT source_anchor_modality_check CHECK (modality IN ('embedded_text', 'structured_pdf', 'docx', 'xlsx', 'ocr', 'visual_table'))");
        DB::statement('ALTER TABLE source_relationships ADD CONSTRAINT source_relation_not_self CHECK (from_anchor_id <> to_anchor_id)');
        DB::statement("ALTER TABLE source_relationships ADD CONSTRAINT source_relation_type_check CHECK (type IN ('contained_by', 'row_header', 'column_header', 'table_parent', 'section_parent', 'caption', 'footnote'))");
        DB::statement("ALTER TABLE evidence_role_bindings ADD CONSTRAINT evidence_role_type_check CHECK (role IN ('entity', 'subject', 'metric', 'value', 'currency', 'unit', 'scale', 'period', 'qualifier', 'direction', 'numerator', 'denominator'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('evidence_role_bindings');
        Schema::dropIfExists('source_relationships');
        Schema::dropIfExists('source_anchors');
        Schema::dropIfExists('source_provenance_sets');
        Schema::table('document_evidence', fn (Blueprint $t) => $t->dropUnique('document_evidence_owner_unique'));
    }
};
