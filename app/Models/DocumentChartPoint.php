<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentChartPoint extends Model
{
    protected $fillable = ['document_chart_id', 'label', 'value', 'sort_order'];

    protected $casts = [
        'value' => 'decimal:4',
    ];

    public function chart()
    {
        return $this->belongsTo(DocumentChart::class, 'document_chart_id');
    }
}