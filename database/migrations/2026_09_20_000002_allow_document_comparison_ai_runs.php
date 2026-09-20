<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE document_ai_runs DROP CONSTRAINT document_ai_runs_purpose_check');
        DB::statement("ALTER TABLE document_ai_runs ADD CONSTRAINT document_ai_runs_purpose_check CHECK (purpose IN ('ocr','insights','document_type','entities','risks','deadlines','document_summary','document_qa','chart_vision','document_comparison'))");
    }

    public function down(): void
    {
        // Refuse a rollback that would invalidate existing audit history.
        if (DB::table('document_ai_runs')->where('purpose', 'document_comparison')->exists()) {
            throw new RuntimeException('Comparison AI audit records exist; retain them before rolling back this constraint.');
        }
        DB::statement('ALTER TABLE document_ai_runs DROP CONSTRAINT document_ai_runs_purpose_check');
        DB::statement("ALTER TABLE document_ai_runs ADD CONSTRAINT document_ai_runs_purpose_check CHECK (purpose IN ('ocr','insights','document_type','entities','risks','deadlines','document_summary','document_qa','chart_vision'))");
    }
};
