<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_notes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['id', 'document_id'], 'document_notes_id_document_unique');
            $table->index(['document_id', 'user_id', 'deleted_at', 'created_at', 'id'], 'document_notes_author_list');
            $table->index('workspace_id');
        });

        DB::statement('ALTER TABLE document_notes ADD CONSTRAINT document_notes_body_check CHECK (char_length(body) BETWEEN 1 AND 20000)');
        DB::statement('ALTER TABLE document_notes ADD CONSTRAINT document_notes_version_check CHECK (version > 0)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_notes_check_owner() RETURNS trigger LANGUAGE plpgsql AS $$
            BEGIN
                IF TG_OP = 'UPDATE' AND (NEW.document_id, NEW.workspace_id, NEW.user_id)
                    IS DISTINCT FROM (OLD.document_id, OLD.workspace_id, OLD.user_id) THEN
                    RAISE EXCEPTION 'document note ownership is immutable';
                END IF;
                IF NOT EXISTS (
                    SELECT 1 FROM documents d
                    WHERE d.id = NEW.document_id AND d.workspace_id = NEW.workspace_id
                ) OR NOT EXISTS (
                    SELECT 1 FROM workspace_members m
                    WHERE m.workspace_id = NEW.workspace_id AND m.user_id = NEW.user_id
                ) THEN
                    RAISE EXCEPTION 'document note owner does not match document and workspace';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER document_notes_owner_guard BEFORE INSERT OR UPDATE ON document_notes
                FOR EACH ROW EXECUTE FUNCTION document_notes_check_owner();
        SQL);

        Schema::create('document_note_citations', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('note_id');
            $table->uuid('document_id');
            $table->uuid('intelligence_revision_id')->nullable();
            $table->string('source_id', 200)->nullable();
            $table->string('finding_label', 200);
            $table->text('quote_snapshot');
            $table->smallInteger('page')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->foreign(['note_id', 'document_id'], 'document_note_citations_note_owner_fk')
                ->references(['id', 'document_id'])->on('document_notes')->cascadeOnDelete();
            $table->index(['note_id', 'created_at', 'id'], 'document_note_citations_list');
        });

        DB::statement('ALTER TABLE document_note_citations ADD CONSTRAINT document_note_citations_quote_check CHECK (char_length(quote_snapshot) BETWEEN 1 AND 1000)');
        DB::statement('ALTER TABLE document_note_citations ADD CONSTRAINT document_note_citations_page_check CHECK (page IS NULL OR page > 0)');
        DB::statement('ALTER TABLE document_note_citations ADD CONSTRAINT document_note_citations_revision_check CHECK (source_id IS NULL OR intelligence_revision_id IS NOT NULL)');
        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION document_note_citations_check_owner() RETURNS trigger LANGUAGE plpgsql AS $$
            DECLARE note_workspace uuid;
            BEGIN
                IF TG_OP = 'UPDATE' THEN
                    RAISE EXCEPTION 'document note citations are immutable';
                END IF;
                SELECT n.workspace_id INTO note_workspace FROM document_notes n
                    WHERE n.id = NEW.note_id AND n.document_id = NEW.document_id;
                IF note_workspace IS NULL THEN
                    RAISE EXCEPTION 'citation note and document do not match';
                END IF;
                IF NEW.intelligence_revision_id IS NOT NULL AND NOT EXISTS (
                    SELECT 1 FROM document_intelligence_revisions r
                    JOIN documents d ON d.id = r.document_id
                    WHERE r.id = NEW.intelligence_revision_id
                      AND r.document_id = NEW.document_id
                      AND r.workspace_id = note_workspace
                      AND r.state = 'published'
                      AND d.published_intelligence_revision_id = r.id
                      AND (NEW.source_id IS NULL OR 1 = (
                          SELECT count(*) FROM document_evidence e
                          WHERE e.document_id = NEW.document_id
                            AND e.workspace_id = note_workspace
                            AND e.pipeline_key = r.pipeline_key
                            AND e.source_id = NEW.source_id
                      ))
                ) THEN
                    RAISE EXCEPTION 'citation revision or source does not belong to the current document';
                END IF;
                RETURN NEW;
            END $$;
            CREATE TRIGGER document_note_citations_owner_guard BEFORE INSERT OR UPDATE ON document_note_citations
                FOR EACH ROW EXECUTE FUNCTION document_note_citations_check_owner();
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_note_citations');
        DB::statement('DROP FUNCTION IF EXISTS document_note_citations_check_owner()');
        Schema::dropIfExists('document_notes');
        DB::statement('DROP FUNCTION IF EXISTS document_notes_check_owner()');
    }
};
