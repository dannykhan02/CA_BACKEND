<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentNoteCitation extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'note_id', 'document_id', 'intelligence_revision_id', 'source_id',
        'finding_label', 'quote_snapshot', 'page', 'created_at',
    ];

    protected $casts = ['page' => 'integer', 'created_at' => 'datetime'];
}
