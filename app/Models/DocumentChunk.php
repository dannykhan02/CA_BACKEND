<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class DocumentChunk extends Model
{
    use HasUuids;

    protected $guarded = [];

    // Eloquent does not reload database defaults after INSERT. Claims inspect these immediately.
    protected $attributes = ['status' => 'pending', 'attempts' => 0, 'reserved_cost' => 0, 'depth' => 0];

    protected $casts = ['result' => 'array', 'cost_accounting' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime',
        'dispatched_at' => 'datetime'];

    /** New owner token for the message about to be dispatched; any older message for this unit becomes stale. */
    public function issueDispatchToken(): string
    {
        $token = (string) Str::uuid();
        $this->forceFill(['dispatch_token' => $token, 'dispatched_at' => now()])->save();

        return $token;
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
