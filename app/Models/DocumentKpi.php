<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentKpi extends Model
{
    protected $fillable = [
        'document_id', 
        'label', 
        'value', 
        'value_numeric', // Added this line to fix mass-assignment issue
        'unit', 
        'trend', 
        'trend_value'
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}


