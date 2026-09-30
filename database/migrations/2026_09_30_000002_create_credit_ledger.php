<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('credit_ledger', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('unit');
            $table->string('direction');
            $table->unsignedInteger('amount');
            $table->string('reason');
            $table->string('related_type')->nullable();
            $table->string('related_id')->nullable();
            $table->string('reference')->unique();
            $table->integer('resulting_balance')->nullable();
            $table->timestamp('created_at');
            $table->index(['workspace_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_ledger');
    }
};
