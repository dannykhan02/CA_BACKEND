<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('billing_operations', fn (Blueprint $table) => $table->unsignedInteger('attempt_number')->default(1));
    }

    public function down(): void
    {
        Schema::table('billing_operations', fn (Blueprint $table) => $table->dropColumn('attempt_number'));
    }
};
