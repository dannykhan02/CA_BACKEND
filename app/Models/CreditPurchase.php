<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditPurchase extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id', 'paystack_reference', 'documents_purchased',
        'amount_kobo_or_cents', 'currency', 'status', 'paystack_response',
        'subscription_id', 'plan_key', 'billing_interval', 'renewal_type', 'provider_plan_code',
        'provider_transaction_id', 'paid_at', 'billing_metadata',
    ];

    protected $casts = [
        'documents_purchased' => 'integer',
        'amount_kobo_or_cents' => 'integer',
        'paystack_response' => 'array', 'billing_metadata' => 'array', 'paid_at' => 'immutable_datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
