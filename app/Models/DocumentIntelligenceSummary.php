<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentIntelligenceSummary extends Model
{
    protected $fillable = [
        'workspace_id', 'document_id', 'executive_summary', 'key_findings',
        'critical_risks', 'upcoming_deadlines', 'important_entities',
        'recommended_attention', 'prompt_version', 'provider', 'model',
    ];

    protected $casts = [
        'key_findings' => 'array',
        'critical_risks' => 'array',
        'upcoming_deadlines' => 'array',
        'important_entities' => 'array',
        'recommended_attention' => 'array',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
