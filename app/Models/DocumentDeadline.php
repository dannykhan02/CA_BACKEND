<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentDeadline extends Model
{
    protected $fillable = [
        'workspace_id',
        'document_id',
        'deadline_type',
        'title',
        'description',
        'due_date',
        'date_type',
        'relative_text',
        'confidence',
        'evidence',
        'status',
        'prompt_version',
        'provider',
        'model',
    ];

    protected $casts = [
        'confidence' => 'decimal:3',
        'due_date' => 'date',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
