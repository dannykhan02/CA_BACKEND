<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Pair-local review decision. Never a global organization identifier. */
class ComparisonIdentityLink extends Model
{
    use HasUuids;

    protected $guarded = [];

    protected $casts = ['evidence' => 'array', 'version' => 'integer', 'reviewed_at' => 'datetime'];
}
