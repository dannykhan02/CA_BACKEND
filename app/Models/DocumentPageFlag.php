<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentPageFlag extends Model
{
    protected $fillable = ['document_id', 'workspace_id', 'page', 'status', 'note'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
