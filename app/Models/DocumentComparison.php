<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentComparison extends Model
{
    use HasUuids;

    protected $fillable = ['workspace_id', 'matter_id', 'base_document_id', 'compared_document_id', 'created_by', 'fingerprint', 'status', 'summary', 'changes', 'metadata', 'error_message', 'dispatch_token', 'dispatched_at', 'provider_started_at'];

    protected $casts = ['changes' => 'array', 'metadata' => 'array', 'dispatched_at' => 'datetime', 'provider_started_at' => 'datetime'];

    public function baseDocument()
    {
        return $this->belongsTo(Document::class, 'base_document_id');
    }

    public function comparedDocument()
    {
        return $this->belongsTo(Document::class, 'compared_document_id');
    }
}
