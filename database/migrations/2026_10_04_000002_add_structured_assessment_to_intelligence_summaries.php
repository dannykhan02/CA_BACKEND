<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_intelligence_summaries', function (Blueprint $table) {
            $table->json('executive_assessment')->nullable();
            $table->json('material_findings')->nullable();
            $table->json('trends')->nullable();
            $table->json('tensions')->nullable();
            $table->json('questions')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_intelligence_summaries', function (Blueprint $table) {
            $table->dropColumn(['executive_assessment', 'material_findings', 'trends', 'tensions', 'questions']);
        });
    }
};
