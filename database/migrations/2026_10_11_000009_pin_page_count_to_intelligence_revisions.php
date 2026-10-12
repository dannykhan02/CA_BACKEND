<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_intelligence_revisions', function (Blueprint $table): void {
            $table->unsignedInteger('pages')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_intelligence_revisions', function (Blueprint $table): void {
            $table->dropColumn('pages');
        });
    }
};
