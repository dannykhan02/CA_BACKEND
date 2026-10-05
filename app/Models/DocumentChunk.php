<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentChunk extends Model
{
    use HasUuids;

    protected $guarded = [];

    // Eloquent does not reload database defaults after INSERT. Claims inspect these immediately.
    protected $attributes = ['status' => 'pending', 'attempts' => 0, 'reserved_cost' => 0, 'depth' => 0];

    protected $casts = ['result' => 'array', 'cost_accounting' => 'array', 'started_at' => 'datetime', 'completed_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
