<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Track A / Day 2 — FU-4, follow-up.
 *
 * The type-check widening from add_type_enum_and_hash_uniqueness_to_documents
 * landed successfully on staging, but that migration's unique-index statement
 * never actually ran under that name — see chat log 2026-09-11 for the full
 * mechanics (a --path targeting mix-up meant the "successful" second run was
 * an empty migration). This migration creates only the missing index; the
 * constraint itself is untouched and left alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS documents_workspace_file_hash_unique ON documents (workspace_id, file_hash) WHERE file_hash IS NOT NULL');
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS documents_workspace_file_hash_unique');
    }
};
