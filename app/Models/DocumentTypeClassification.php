<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentTypeClassification extends Model
{
    protected $fillable = [
        'intelligence_revision_id',
        'workspace_id',
        'document_id',
        'document_type',
        'confidence',
        'reasoning',
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
