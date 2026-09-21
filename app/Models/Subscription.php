<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    protected $guarded = [];

    protected $casts = [
        'grandfathered' => 'boolean', 'auto_renews' => 'boolean', 'cancel_at_period_end' => 'boolean', 'metadata' => 'array',
        'current_period_start' => 'immutable_datetime', 'current_period_end' => 'immutable_datetime',
        'next_payment_date' => 'immutable_datetime', 'cancelled_at' => 'immutable_datetime', 'ended_at' => 'immutable_datetime',
    ];

    public function periods()
    {
        return $this->hasMany(SubscriptionUsagePeriod::class);
    }
}
