<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedTinyInteger('extraction_attempts')->default(0)->after('file_hash');
            $table->timestamp('extraction_started_at')->nullable()->after('extraction_attempts');
            $table->timestamp('extraction_completed_at')->nullable()->after('extraction_started_at');
            // Raw model token usage, kept for cost auditing — not exposed via API.
            $table->unsignedInteger('extraction_input_tokens')->nullable()->after('extraction_completed_at');
            $table->unsignedInteger('extraction_output_tokens')->nullable()->after('extraction_input_tokens');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'extraction_attempts',
                'extraction_started_at',
                'extraction_completed_at',
                'extraction_input_tokens',
                'extraction_output_tokens',
            ]);
        });
    }
};
