<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentAiRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'document_id', 'file_hash', 'purpose', 'provider',
        'chunk_id', 'pipeline_version', 'request_attempt', 'cache_creation_tokens', 'cache_read_tokens',
        'duration_ms', 'process_peak_memory_bytes', 'estimated_cost_usd', 'failure_class', 'provider_request_id', 'partial',
        'evidence_trimmed', 'optional_items_dropped', 'model', 'prompt_version', 'input_tokens', 'output_tokens', 'status', 'stop_reason', 'created_at', 'operation_quote_id', 'user_id', 'comparison_id',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
