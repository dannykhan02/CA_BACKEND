<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SubscriptionUsagePeriod extends Model
{
    protected $guarded = [];

    protected $casts = ['period_start' => 'immutable_datetime', 'period_end' => 'immutable_datetime'];
}
