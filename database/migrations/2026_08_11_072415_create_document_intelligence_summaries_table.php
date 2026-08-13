<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_intelligence_summaries', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->text('executive_summary');
            $table->json('key_findings');
            $table->json('critical_risks');
            $table->json('upcoming_deadlines');
            $table->json('important_entities');
            $table->json('recommended_attention');
            $table->string('prompt_version');
            $table->string('provider')->default('anthropic');
            $table->string('model')->nullable();
            $table->timestamps();

            $table->unique('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_intelligence_summaries');
    }
};
