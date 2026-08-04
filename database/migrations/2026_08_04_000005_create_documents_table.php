<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->enum('type', ['PDF', 'DOCX']);
            $table->unsignedInteger('size_kb');
            $table->enum('status', ['Processing', 'Ready', 'Needs Review', 'Failed'])
                  ->default('Processing');
            // On Postgres, enum() already compiles to a CHECK constraint
            // (documents_classification_check) automatically — no need to
            // add a second one manually, that's what caused the "duplicate
            // object" failure.
            $table->enum('classification', ['Public', 'Internal', 'Confidential', 'Restricted']);
            $table->unsignedSmallInteger('year');

            $table->foreignUuid('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('last_updated_by')->nullable()->constrained('users');
            $table->foreignUuid('workspace_id')->nullable()->constrained('workspaces')->nullOnDelete();

            $table->unsignedInteger('pages')->default(0);
            $table->boolean('has_structured_data')->default(false);
            $table->unsignedTinyInteger('progress')->nullable();
            $table->text('error_message')->nullable();
            $table->enum('power_bi_status', ['synced', 'not-synced', 'failed'])
                  ->default('not-synced');
            $table->json('insights')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_hash', 64)->nullable();

            $table->unsignedTinyInteger('extraction_attempts')->default(0);
            $table->timestamp('extraction_started_at')->nullable();
            $table->timestamp('extraction_completed_at')->nullable();
            // Raw model token usage, kept for cost auditing — not exposed via API.
            $table->unsignedInteger('extraction_input_tokens')->nullable();
            $table->unsignedInteger('extraction_output_tokens')->nullable();

            $table->timestamps();

            $table->index('status');
            $table->index('classification');
            $table->index('year');
            $table->index('uploaded_by');
            $table->index('file_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};