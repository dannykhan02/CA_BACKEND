<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentEntity extends Model
{
    protected $fillable = [
        'workspace_id',
        'document_id',
        'entity_type',
        'value',
        'normalized_value',
        'confidence',
        'context',
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
