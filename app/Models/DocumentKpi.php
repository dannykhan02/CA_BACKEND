<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentKpi extends Model
{
    protected $fillable = [
        'document_id',
        'workspace_id',
        'label',
        'value',
        'value_numeric',
        'unit',
        'trend',
        'trend_value',
    ];

    protected $casts = [
        'value_numeric' => 'decimal:4',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
