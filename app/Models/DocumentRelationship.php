<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentRelationship extends Model
{
    use HasUuids;

    protected $fillable = ['workspace_id', 'from_document_id', 'to_document_id', 'relationship_type', 'note', 'created_by'];

    public function fromDocument()
    {
        return $this->belongsTo(Document::class, 'from_document_id');
    }

    public function toDocument()
    {
        return $this->belongsTo(Document::class, 'to_document_id');
    }
}
