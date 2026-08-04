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
            $table->enum('classification', ['Public', 'Internal', 'Confidential', 'Restricted']);
            $table->unsignedSmallInteger('year');
            $table->foreignUuid('uploaded_by')->constrained('users');
            $table->foreignUuid('last_updated_by')->nullable()->constrained('users');
            $table->unsignedInteger('pages')->default(0);
            $table->boolean('has_structured_data')->default(false);
            $table->unsignedTinyInteger('progress')->nullable();
            $table->text('error_message')->nullable();
            $table->enum('power_bi_status', ['synced', 'not-synced', 'failed'])
                  ->default('not-synced');
            $table->json('insights')->nullable();
            $table->string('file_path')->nullable();
            $table->string('file_hash', 64)->nullable();
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
