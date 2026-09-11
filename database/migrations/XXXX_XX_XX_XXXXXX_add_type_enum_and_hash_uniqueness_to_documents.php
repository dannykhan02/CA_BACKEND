<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Track A / Day 2 — FU-4 + FU-8.
 *
 * FU-8: documents.type's check constraint only allowed ['PDF','DOCX'], but
 * config/document_types.php has XLSX, JPG, and PNG enabled at the app layer.
 * Per that config file's own stated intent, `enabled` is an application-level
 * kill switch independent of what the schema can store — so the constraint
 * is widened to every type key document_types.php currently knows about
 * (enabled or not), not just the three live ones today. Enabling a new type
 * later stays an app-config change; it should not require another migration.
 *
 * FU-4: no DB-level uniqueness ever backed the check-then-create dedup in
 * DocumentUploadController::store() — only a single-column index on
 * file_hash existed. Adding a partial unique index (file_hash IS NOT NULL,
 * since the column is nullable) on (workspace_id, file_hash), matching this
 * codebase's existing partial-unique-index convention used for prompt
 * versioning.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_type_check');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type IN ('PDF','DOCX','XLSX','JPG','PNG','TIFF','CSV','DOC'))");

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS documents_workspace_file_hash_unique ON documents (workspace_id, file_hash) WHERE file_hash IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_workspace_file_hash_unique');

        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_type_check');
        DB::statement("ALTER TABLE documents ADD CONSTRAINT documents_type_check CHECK (type IN ('PDF','DOCX'))");
    }
};
