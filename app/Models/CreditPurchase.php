<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CreditPurchase extends Model
{
    protected $fillable = [
        'workspace_id', 'user_id', 'paystack_reference', 'documents_purchased',
        'amount_kobo_or_cents', 'currency', 'status', 'paystack_response',
    ];

    protected $casts = [
        'documents_purchased' => 'integer',
        'amount_kobo_or_cents' => 'integer',
        'paystack_response' => 'array',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
