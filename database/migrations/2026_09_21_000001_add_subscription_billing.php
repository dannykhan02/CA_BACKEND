<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trial_grants', function (Blueprint $t) {
            $t->unsignedInteger('initial_credits')->nullable();
        });
        Schema::create('subscriptions', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('workspace_id')->unique()->constrained()->restrictOnDelete();
            $t->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $t->string('plan_key');
            $t->string('billing_interval');
            $t->string('provider')->default('paystack');
            $t->string('provider_customer_code')->nullable()->index();
            $t->string('provider_subscription_code')->nullable()->unique();
            $t->string('provider_plan_code')->nullable()->index();
            $t->string('status')->default('expired')->index();
            $t->boolean('grandfathered')->default(false);
            $t->boolean('auto_renews')->default(false);
            $t->boolean('cancel_at_period_end')->default(false);
            foreach (['current_period_start', 'current_period_end', 'next_payment_date', 'cancelled_at', 'ended_at'] as $column) {
                $t->timestamp($column)->nullable();
            }
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::table('credit_purchases', function (Blueprint $t) {
            $t->foreignId('subscription_id')->nullable()->constrained()->restrictOnDelete();
            $t->string('plan_key')->nullable();
            $t->string('billing_interval')->nullable();
            $t->string('renewal_type')->nullable();
            $t->string('provider_plan_code')->nullable();
            $t->string('provider_transaction_id')->nullable()->unique();
            $t->timestamp('paid_at')->nullable();
            $t->json('billing_metadata')->nullable();
        });
        Schema::create('subscription_usage_periods', function (Blueprint $t) {
            $t->id();
            $t->foreignId('subscription_id')->constrained()->restrictOnDelete();
            $t->timestamp('period_start');
            $t->timestamp('period_end');
            $t->unsignedInteger('documents_allowed');
            $t->unsignedInteger('documents_used')->default(0);
            $t->unsignedInteger('comparisons_allowed');
            $t->unsignedInteger('comparisons_used')->default(0);
            $t->unsignedBigInteger('storage_bytes');
            $t->timestamps();
            $t->unique(['subscription_id', 'period_start']);
        });
        Schema::create('billing_operations', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('workspace_id')->constrained()->restrictOnDelete();
            $t->foreignId('usage_period_id')->nullable()->constrained('subscription_usage_periods')->restrictOnDelete();
            $t->string('kind');
            $t->uuid('resource_id');
            $t->string('status')->default('reserved');
            $t->timestamps();
            $t->unique(['kind', 'resource_id']);
            $t->index(['workspace_id', 'status']);
        });
        Schema::create('billing_webhook_events', function (Blueprint $t) {
            $t->id();
            $t->string('event_key', 64)->unique();
            $t->string('event_type')->index();
            $t->json('payload');
            $t->timestamp('processed_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::table('trial_grants', function (Blueprint $t) {
            $t->dropColumn('initial_credits');
        });
        Schema::dropIfExists('billing_webhook_events');
        Schema::dropIfExists('billing_operations');
        Schema::dropIfExists('subscription_usage_periods');
        Schema::table('credit_purchases', function (Blueprint $t) {
            $t->dropConstrainedForeignId('subscription_id');
            $t->dropColumn(['plan_key', 'billing_interval', 'renewal_type', 'provider_plan_code', 'provider_transaction_id', 'paid_at', 'billing_metadata']);
        });
        Schema::dropIfExists('subscriptions');
    }
};
