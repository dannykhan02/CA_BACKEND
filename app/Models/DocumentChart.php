<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentChart extends Model
{
    protected $fillable = ['document_id', 'workspace_id', 'type', 'title', 'description', 'data'];

    protected $casts = [
        'data' => 'array',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    // Normalized rows for the Power BI reporting layer — kept alongside the
    // existing `data` JSON column rather than replacing it, since the app's
    // own frontend chart rendering reads `data` directly and shouldn't need
    // to change. `points` exists purely so BI/reporting tools can query
    // real rows instead of unpacking JSON, which most BI connectors handle
    // poorly or not at all.
    public function points()
    {
        return $this->hasMany(DocumentChartPoint::class)->orderBy('sort_order');
    }
}
