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
        'provider_started_at', 'provider_finished_at', 'timeout_source', 'provider_response_received', 'input_tokens_counted',
        'dispatched_at', 'worker_started_at', 'admission_requested_at', 'lease_acquired_at', 'lease_released_at',
        'worker_wait_ms', 'fairness_wait_ms', 'admission_wait_ms', 'dispatch_reason',
        'wire_format_version', 'compact_codec_version', 'raw_provider_response_hash',
        'expanded_canonical_response_hash', 'provider_schema_hash', 'canonical_schema_hash',
        'prompt_hash', 'request_body_hash', 'source_hash', 'span_hash', 'raw_provider_response',
    ];

    protected $casts = ['created_at' => 'datetime', 'provider_started_at' => 'datetime', 'provider_finished_at' => 'datetime',
        'provider_response_received' => 'boolean', 'dispatched_at' => 'datetime', 'worker_started_at' => 'datetime',
        'admission_requested_at' => 'datetime', 'lease_acquired_at' => 'datetime', 'lease_released_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
