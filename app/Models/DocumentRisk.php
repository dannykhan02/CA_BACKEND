<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentRisk extends Model
{
    protected $fillable = [
        'workspace_id',
        'document_id',
        'risk_type',
        'title',
        'description',
        'severity',
        'confidence',
        'evidence',
        'status',
        'prompt_version',
        'provider',
        'model',
    ];

    protected $casts = [
        'confidence' => 'decimal:3',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
