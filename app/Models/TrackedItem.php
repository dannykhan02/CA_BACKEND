<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class TrackedItem extends Model
{
    use HasUuids;

    protected $fillable = ['workspace_id', 'document_id', 'matter_id', 'created_by', 'source_key', 'source', 'title', 'type', 'due_date', 'status', 'notes', 'completed_at', 'remind_at', 'reminded_at'];

    protected $casts = ['source' => 'array', 'due_date' => 'date', 'completed_at' => 'datetime', 'remind_at' => 'datetime', 'reminded_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
