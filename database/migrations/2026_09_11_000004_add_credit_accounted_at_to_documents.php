<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->timestamp('credit_accounted_at')->nullable();
        });
        // Previously delivered analyses must not be billed retroactively.
        DB::table('documents')->where('status', 'Ready')->update(['credit_accounted_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->dropColumn('credit_accounted_at'));
    }
};
