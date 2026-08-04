<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OcrResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'document_id', 'page_number',
        'engine', 'raw_text', 'confidence', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'confidence' => 'decimal:4',
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
