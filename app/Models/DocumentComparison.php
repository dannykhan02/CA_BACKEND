<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentComparison extends Model
{
    use HasUuids;

    protected $fillable = ['workspace_id', 'matter_id', 'base_document_id', 'compared_document_id', 'created_by', 'fingerprint', 'status', 'summary', 'changes', 'metadata', 'error_message'];

    protected $casts = ['changes' => 'array', 'metadata' => 'array'];

    public function baseDocument()
    {
        return $this->belongsTo(Document::class, 'base_document_id');
    }

    public function comparedDocument()
    {
        return $this->belongsTo(Document::class, 'compared_document_id');
    }
}
