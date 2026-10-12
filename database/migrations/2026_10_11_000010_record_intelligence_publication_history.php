<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_intelligence_revisions', function (Blueprint $table): void {
            $table->uuid('previous_published_revision_id')->nullable();
            $table->string('publication_reason', 32)->nullable();
            $table->foreign('previous_published_revision_id', 'intelligence_revision_previous_fk')
                ->references('id')->on('document_intelligence_revisions')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_intelligence_revisions', function (Blueprint $table): void {
            $table->dropForeign('intelligence_revision_previous_fk');
            $table->dropColumn(['previous_published_revision_id', 'publication_reason']);
        });
    }
};
