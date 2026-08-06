<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Second widening pass — adds the "prepared but disabled" types from the
 * document-types roadmap (TIFF, CSV, DOC). JPG/PNG/PDF/DOCX/XLSX were
 * already added in the first widening migration. The DB now accepts all
 * eight; config/document_types.php's `enabled` flag is the actual gate on
 * whether a type can be uploaded — this migration only says the schema
 * *can* hold the value, not that the app will accept it yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_type_check');

        DB::statement(
            "ALTER TABLE documents ADD CONSTRAINT documents_type_check
             CHECK (type IN ('PDF','DOCX','XLSX','JPG','PNG','TIFF','CSV','DOC'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_type_check');
        DB::statement(
            "ALTER TABLE documents ADD CONSTRAINT documents_type_check
             CHECK (type IN ('PDF','DOCX','XLSX','JPG','PNG'))"
        );
    }
};