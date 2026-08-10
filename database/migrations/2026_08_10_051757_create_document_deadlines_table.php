<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_deadlines', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->string('deadline_type')->nullable();
            $table->string('title');
            $table->text('description');
            $table->date('due_date')->nullable();
            $table->string('date_type');
            $table->string('relative_text')->nullable();
            $table->decimal('confidence', 4, 3);
            $table->text('evidence');
            $table->string('status')->default('open');
            $table->string('prompt_version');
            $table->string('provider')->default('anthropic');
            $table->string('model')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'date_type']);
            $table->index(['workspace_id', 'due_date']);
        });

        DB::statement("ALTER TABLE document_deadlines ADD CONSTRAINT document_deadlines_date_type_check
            CHECK (date_type IN ('explicit','relative','inferred'))");

        DB::statement("ALTER TABLE document_deadlines ADD CONSTRAINT document_deadlines_status_check
            CHECK (status IN ('open','met','missed'))");

        DB::statement('ALTER TABLE document_deadlines ADD CONSTRAINT document_deadlines_confidence_check
            CHECK (confidence >= 0 AND confidence <= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_deadlines');
    }
};
