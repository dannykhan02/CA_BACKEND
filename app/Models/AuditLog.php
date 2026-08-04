<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasUuids;

    // Append-only: no updated_at column, and application code should never
    // update or delete a row here outside of a documented retention policy.
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'workspace_id', 'action', 'auditable_type',
        'auditable_id', 'metadata', 'ip_address', 'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
