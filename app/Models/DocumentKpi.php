<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentKpi extends Model
{
    protected $fillable = ['document_id', 'label', 'value', 'unit', 'trend', 'trend_value'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}