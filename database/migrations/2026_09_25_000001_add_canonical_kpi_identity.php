<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_definitions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->string('canonical_name');
            $table->string('normalized_name', 2048);
            $table->string('concept', 2048)->nullable();
            $table->string('scope', 2048)->nullable();
            $table->string('metric_type', 40)->nullable();
            $table->string('unit', 2048)->nullable();
            $table->jsonb('matching_metadata');
            $table->char('identity_key', 64);
            $table->timestamps();
            $table->unique(['workspace_id', 'id']);
            // Names alone are not unique: count/rate, actual/target, etc. can be homonyms.
            $table->unique(['workspace_id', 'identity_key']);
            $table->index(['workspace_id', 'normalized_name']);
            $table->index(['workspace_id', 'concept']);
        });

        Schema::create('kpi_aliases', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->uuid('kpi_definition_id');
            $table->string('label');
            $table->string('normalized_label', 2048);
            $table->char('context_key', 64);
            $table->jsonb('matching_metadata');
            $table->string('method', 40);
            $table->timestamps();
            $table->foreign(['workspace_id', 'kpi_definition_id'])
                ->references(['workspace_id', 'id'])->on('kpi_definitions')->cascadeOnDelete();
            // The same words may legitimately identify distinct measurement contexts.
            $table->unique(['workspace_id', 'normalized_label', 'context_key'], 'kpi_alias_context_unique');
            $table->index('kpi_definition_id');
        });

        Schema::table('document_kpis', function (Blueprint $table) {
            // Existing workspace FKs clear child ownership while definitions cascade.
            // Validate this scalar FK after those actions finish, not mid-cascade.
            $table->foreignUuid('kpi_definition_id')->nullable()->constrained('kpi_definitions')
                ->nullOnDelete()->deferrable()->initiallyImmediate(false);
            $table->jsonb('identity_metadata')->nullable();
            $table->string('period')->nullable();
            $table->index(['workspace_id', 'kpi_definition_id']);
            $table->index('kpi_definition_id');
        });
        // SET NULL only the identity column: deleting a definition or a workspace
        // must preserve both extracted labels and the existing child ownership rules.
        DB::statement('ALTER TABLE document_kpis ADD CONSTRAINT document_kpis_definition_workspace_fk
            FOREIGN KEY (workspace_id, kpi_definition_id) REFERENCES kpi_definitions (workspace_id, id)
            ON DELETE SET NULL (kpi_definition_id)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE document_kpis DROP CONSTRAINT document_kpis_definition_workspace_fk');
        Schema::table('document_kpis', function (Blueprint $table) {
            $table->dropForeign(['kpi_definition_id']);
            $table->dropIndex(['workspace_id', 'kpi_definition_id']);
            $table->dropIndex(['kpi_definition_id']);
            $table->dropColumn(['kpi_definition_id', 'identity_metadata', 'period']);
        });
        Schema::dropIfExists('kpi_aliases');
        Schema::dropIfExists('kpi_definitions');
    }
};
