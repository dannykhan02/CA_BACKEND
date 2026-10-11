<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('structured_extractions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id');
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('source_hash', 64);
            $table->string('text_hash', 64);
            $table->string('parser_version', 64);
            $table->string('policy_version', 64);
            $table->string('format', 8);
            $table->json('capabilities');
            $table->json('nodes');
            $table->boolean('active')->default(false);
            $table->timestamps();
            $table->unique(['document_id', 'source_hash', 'text_hash', 'parser_version', 'policy_version'], 'structured_revision_unique');
            $table->index(['workspace_id', 'document_id', 'active'], 'structured_owner_active');
        });
        DB::statement('CREATE UNIQUE INDEX structured_one_active ON structured_extractions (document_id) WHERE active');
    }

    public function down(): void
    {
        Schema::dropIfExists('structured_extractions');
    }
};
