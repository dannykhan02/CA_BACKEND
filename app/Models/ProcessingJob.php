<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProcessingJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'document_id', 'stage', 'status',
        'input', 'output', 'error_message',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
