<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentEmbedding extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'document_id', 'chunk_index',
        'chunk_text', 'provider', 'model', 'embedding',
    ];

    public function document() { return $this->belongsTo(Document::class); }
    public function workspace() { return $this->belongsTo(Workspace::class); }
}
