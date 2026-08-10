<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_risks', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->string('risk_type')->nullable();
            $table->string('title');
            $table->text('description');
            $table->string('severity');
            $table->decimal('confidence', 4, 3);
            $table->text('evidence');
            $table->string('status')->default('open');
            $table->string('prompt_version');
            $table->string('provider')->default('anthropic');
            $table->string('model')->nullable();
            $table->timestamps();

            $table->index(['document_id', 'severity']);
            $table->index(['workspace_id', 'status']);
        });

        DB::statement("ALTER TABLE document_risks ADD CONSTRAINT document_risks_severity_check
            CHECK (severity IN ('low','medium','high','critical'))");

        DB::statement("ALTER TABLE document_risks ADD CONSTRAINT document_risks_status_check
            CHECK (status IN ('open','mitigated','closed'))");

        DB::statement('ALTER TABLE document_risks ADD CONSTRAINT document_risks_confidence_check
            CHECK (confidence >= 0 AND confidence <= 1)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_risks');
    }
};
