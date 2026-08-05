<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_ai_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->enum('purpose', ['ocr', 'insights']);
            $table->string('provider');
            $table->string('model')->nullable();
            $table->string('prompt_version')->nullable();
            $table->unsignedInteger('input_tokens')->nullable();
            $table->unsignedInteger('output_tokens')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['document_id', 'purpose']);
            $table->index(['workspace_id', 'model']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_ai_runs');
    }
};