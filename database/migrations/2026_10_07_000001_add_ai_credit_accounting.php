<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Additive and forward-only: no historical ledger row or legacy allowance column is touched. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_quotes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $t->string('kind'); // document | reanalysis | comparison | qa
            $t->string('resource_id');
            $t->string('operation_key')->unique(); // kind:resource:attempt - one quote per attempt
            $t->string('quote_version');
            $t->string('band');
            $t->unsignedInteger('credits'); // immutable: the exact price shown at admission
            $t->string('funding_bucket')->nullable();
            $t->decimal('provider_cost_cap_usd', 12, 6);
            $t->jsonb('preflight'); // classification inputs (counts and flags only, never content)
            $t->string('status')->default('quoted'); // quoted | reserved | settled | released
            $t->string('release_reason')->nullable();
            $t->timestamp('accepted_at')->nullable();
            $t->timestamp('reserved_at')->nullable();
            $t->timestamp('settled_at')->nullable();
            $t->timestamp('released_at')->nullable();
            $t->timestamps();
            $t->index(['workspace_id', 'status']);
            $t->index(['kind', 'resource_id']);
        });

        Schema::table('billing_operations', function (Blueprint $t) {
            $t->uuid('quote_id')->nullable()->index();
            $t->unsignedInteger('amount_reserved')->nullable(); // null = legacy 1-unit operation
            $t->unsignedInteger('amount_settled')->nullable();
            $t->string('funding_bucket')->nullable();
            $t->string('quote_version')->nullable();
            $t->string('release_reason')->nullable();
        });

        Schema::table('subscription_usage_periods', function (Blueprint $t) {
            // null = a legacy document/comparison period; the old allowance columns keep governing it.
            $t->unsignedInteger('ai_credits_allowed')->nullable();
            $t->unsignedInteger('ai_credits_used')->default(0);
        });

        Schema::table('workspace_credits', fn (Blueprint $t) => $t->unsignedBigInteger('ai_credits_remaining')->default(0));

        Schema::table('document_ai_runs', function (Blueprint $t) {
            $t->uuid('operation_quote_id')->nullable()->index();
            $t->uuid('user_id')->nullable();
            $t->uuid('comparison_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('document_ai_runs', fn (Blueprint $t) => $t->dropColumn(['operation_quote_id', 'user_id', 'comparison_id']));
        Schema::table('workspace_credits', fn (Blueprint $t) => $t->dropColumn('ai_credits_remaining'));
        Schema::table('subscription_usage_periods', fn (Blueprint $t) => $t->dropColumn(['ai_credits_allowed', 'ai_credits_used']));
        Schema::table('billing_operations', fn (Blueprint $t) => $t->dropColumn(['quote_id', 'amount_reserved', 'amount_settled', 'funding_bucket', 'quote_version', 'release_reason']));
        Schema::dropIfExists('operation_quotes');
    }
};
