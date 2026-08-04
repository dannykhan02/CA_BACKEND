<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceAiConfig extends Model
{
    protected $primaryKey = 'workspace_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'provider',
        'model',
        'temperature',
        'api_key_encrypted',
        'max_tokens',
        'vision_enabled',
    ];

    protected $casts = [
        // Laravel encrypts/decrypts this transparently on every read/write.
        // Never read $attributes['api_key_encrypted'] directly, and never
        // log this model's full attribute array.
        'api_key_encrypted' => 'encrypted',
        'temperature' => 'decimal:2',
        'vision_enabled' => 'boolean',
    ];

    protected $hidden = [
        'api_key_encrypted',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
