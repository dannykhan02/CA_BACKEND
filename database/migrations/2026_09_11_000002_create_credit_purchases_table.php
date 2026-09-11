<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $table->string('paystack_reference')->unique();
            $table->unsignedInteger('documents_purchased');
            $table->unsignedInteger('amount_kobo_or_cents');
            // Snapshot the currency so config changes cannot reinterpret history.
            $table->char('currency', 3);
            $table->enum('status', ['pending', 'completed', 'failed'])->default('pending');
            $table->json('paystack_response')->nullable();
            $table->timestamps();
            $table->index(['workspace_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_purchases');
    }
};
