<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/**
 * The exact price shown for one billable operation attempt. The priced columns are immutable:
 * later config changes, retries and provider cost variance never rewrite a historical charge.
 */
class OperationQuote extends Model
{
    use HasUuids;

    private const IMMUTABLE = ['workspace_id', 'kind', 'resource_id', 'operation_key', 'quote_version', 'band', 'credits', 'provider_cost_cap_usd', 'preflight'];

    protected $guarded = [];

    protected $casts = [
        'credits' => 'integer',
        'provider_cost_cap_usd' => 'float',
        'preflight' => 'array',
        'accepted_at' => 'datetime', 'reserved_at' => 'datetime', 'settled_at' => 'datetime', 'released_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $quote) {
            foreach (self::IMMUTABLE as $column) {
                if ($quote->isDirty($column)) {
                    throw new \LogicException("Operation quote column {$column} is immutable.");
                }
            }
        });
    }
}
