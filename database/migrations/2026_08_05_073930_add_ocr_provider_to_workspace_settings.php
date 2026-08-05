<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->string('ocr_provider')->nullable()->after('ocr_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('workspace_settings', function (Blueprint $table) {
            $table->dropColumn('ocr_provider');
        });
    }
};