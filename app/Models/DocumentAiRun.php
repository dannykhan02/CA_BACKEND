<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentAiRun extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id', 'document_id', 'purpose', 'provider',
        'model', 'prompt_version', 'input_tokens', 'output_tokens', 'created_at',
    ];

    protected $casts = ['created_at' => 'datetime'];

    public function document() { return $this->belongsTo(Document::class); }
    public function workspace() { return $this->belongsTo(Workspace::class); }
}
