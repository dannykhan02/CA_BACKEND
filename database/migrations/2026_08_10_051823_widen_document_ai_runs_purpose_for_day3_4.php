<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE document_ai_runs DROP CONSTRAINT document_ai_runs_purpose_check');
        DB::statement("ALTER TABLE document_ai_runs ADD CONSTRAINT document_ai_runs_purpose_check
            CHECK (purpose IN ('ocr','insights','document_type','entities','risks','deadlines'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE document_ai_runs DROP CONSTRAINT document_ai_runs_purpose_check');
        DB::statement("ALTER TABLE document_ai_runs ADD CONSTRAINT document_ai_runs_purpose_check
            CHECK (purpose IN ('ocr','insights','document_type'))");
    }
};
