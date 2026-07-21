<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentChart extends Model
{
    protected $fillable = ['document_id', 'type', 'title', 'description', 'data'];

    protected $casts = [
        'data' => 'array',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}