<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds the optional 'image_text_location' stage.
 *
 * A distinct stage name rather than reusing 'ocr_check' (which OcrPageBatchJob
 * shares across its batches): this stage is optional and must stay
 * distinguishable from real OCR, because a required stage and an optional one
 * sharing a name is how an optional failure starts looking like a blocked
 * revision.
 */
return new class extends Migration
{
    private const STAGES = "'virus_scan','file_detection','ocr_check','extract','normalize','chunk','ai_analysis','kpis','charts','power_bi','document_type','entities','risks','deadlines','document_summary'";

    public function up(): void
    {
        DB::statement('ALTER TABLE processing_jobs DROP CONSTRAINT processing_jobs_stage_check');
        DB::statement('ALTER TABLE processing_jobs ADD CONSTRAINT processing_jobs_stage_check
            CHECK (stage IN ('.self::STAGES.",'image_text_location'))");
    }

    public function down(): void
    {
        DB::table('processing_jobs')->where('stage', 'image_text_location')->delete();
        DB::statement('ALTER TABLE processing_jobs DROP CONSTRAINT processing_jobs_stage_check');
        DB::statement('ALTER TABLE processing_jobs ADD CONSTRAINT processing_jobs_stage_check
            CHECK (stage IN ('.self::STAGES.'))');
    }
};
