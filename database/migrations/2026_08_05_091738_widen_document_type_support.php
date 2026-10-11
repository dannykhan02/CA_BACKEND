<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_type_check');

        Schema::table('documents', function ($table) {
            $table->string('type', 10)->change();
        });

        DB::statement(
            "ALTER TABLE documents ADD CONSTRAINT documents_type_check
             CHECK (type IN ('PDF','DOCX','JPG','PNG','XLSX'))"
        );
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE documents DROP CONSTRAINT IF EXISTS documents_type_check');
        DB::statement(
            "ALTER TABLE documents ADD CONSTRAINT documents_type_check
             CHECK (type IN ('PDF','DOCX'))"
        );
        Schema::table('documents', function ($table) {
            // PostgreSQL implements this enum as VARCHAR plus the check above.
            // Laravel's enum()->change() emits invalid ALTER COLUMN SQL here.
            $table->string('type', 255)->change();
        });
    }
};
