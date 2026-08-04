<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'document_id', 'version_number',
        'file_path', 'file_hash', 'created_by', 'change_note',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
