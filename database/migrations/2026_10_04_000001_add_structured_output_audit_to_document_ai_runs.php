<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_ai_runs', function (Blueprint $table) {
            $table->string('status', 32)->default('success');
            $table->string('stop_reason', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('document_ai_runs', fn (Blueprint $table) => $table->dropColumn(['status', 'stop_reason']));
    }
};
