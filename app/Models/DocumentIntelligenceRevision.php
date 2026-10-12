<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentIntelligenceRevision extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(function (self $revision): void {
            if ($revision->getOriginal('state') === 'published' && $revision->isDirty()) {
                throw new \LogicException('Published intelligence revisions are immutable.');
            }
        });
    }

    protected $guarded = [];

    protected $casts = [
        'ai_pipeline' => 'array',
        'insights' => 'array',
        'has_structured_data' => 'boolean',
        'pages' => 'integer',
        'ready_at' => 'datetime',
        'published_at' => 'datetime',
        'failed_at' => 'datetime',
    ];
}
