<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE processing_jobs DROP CONSTRAINT processing_jobs_stage_check');
        DB::statement("ALTER TABLE processing_jobs ADD CONSTRAINT processing_jobs_stage_check
            CHECK (stage IN ('virus_scan','file_detection','ocr_check','extract','normalize','chunk','ai_analysis','kpis','charts','power_bi','document_type'))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE processing_jobs DROP CONSTRAINT processing_jobs_stage_check');
        DB::statement("ALTER TABLE processing_jobs ADD CONSTRAINT processing_jobs_stage_check
            CHECK (stage IN ('virus_scan','file_detection','ocr_check','extract','normalize','chunk','ai_analysis','kpis','charts','power_bi'))");
    }
};