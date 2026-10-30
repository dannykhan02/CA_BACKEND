<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comparison_identity_links', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_a_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignUuid('document_b_id')->constrained('documents')->cascadeOnDelete();
            $table->uuid('revision_a_id');
            $table->uuid('revision_b_id');
            $table->string('record_a_id', 100);
            $table->string('record_b_id', 100);
            $table->string('kind', 32);
            $table->string('binding_key', 64);
            $table->string('state', 24);
            $table->unsignedInteger('version');
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at');
            $table->text('reason');
            $table->jsonb('evidence');
            $table->timestampsTz();
            $table->unique(['workspace_id', 'binding_key']);
            $table->index(['workspace_id', 'document_a_id', 'document_b_id'], 'identity_pair_index');
        });
        Schema::create('comparison_identity_events', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignUuid('link_id')->constrained('comparison_identity_links')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('state', 24);
            $table->foreignUuid('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('reviewed_at');
            $table->text('reason');
            $table->unique(['link_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comparison_identity_events');
        Schema::dropIfExists('comparison_identity_links');
    }
};
