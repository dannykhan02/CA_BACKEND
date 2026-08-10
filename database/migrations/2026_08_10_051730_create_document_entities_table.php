<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_entities', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->string('entity_type');
            $table->string('value');
            $table->string('normalized_value')->nullable();
            $table->decimal('confidence', 4, 3);
            $table->text('context')->nullable();
            $table->string('prompt_version');
            $table->string('provider')->default('anthropic');
            $table->string('model')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'entity_type']);
            $table->index(['workspace_id', 'entity_type']);
        });

        DB::statement("ALTER TABLE document_entities ADD CONSTRAINT document_entities_type_check
            CHECK (entity_type IN ('organization','person','department','location','regulator','contract','reference','date','other'))");

        DB::statement('ALTER TABLE document_entities ADD CONSTRAINT document_entities_confidence_check
            CHECK (confidence >= 0 AND confidence <= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_entities');
    }
};
