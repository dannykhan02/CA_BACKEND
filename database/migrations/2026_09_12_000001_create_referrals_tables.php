<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('referral_codes', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('code', 8)->unique();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('referrals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referral_code_id')->constrained()->cascadeOnDelete();
            // Retain earned-credit history if a referred account is deleted.
            $table->foreignUuid('referred_user_id')->nullable()->unique()->constrained('users')->nullOnDelete();
            $table->enum('status', ['pending', 'rewarded'])->default('pending');
            $table->boolean('reward_eligible')->default(false);
            $table->string('ineligible_reason')->nullable();
            $table->unsignedInteger('reward_documents')->default(0);
            $table->foreignUuid('rewarded_workspace_id')->nullable()->constrained('workspaces')->restrictOnDelete();
            $table->timestamp('rewarded_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('credit_purchases', function (Blueprint $table) {
            // Historical purchases have no reliable purchaser identity, especially
            // in organizations. Keep them unattributed instead of guessing.
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->index(['user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('credit_purchases', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status']);
            $table->dropConstrainedForeignId('user_id');
        });
        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_codes');
    }
};
