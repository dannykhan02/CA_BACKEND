<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** Offsets into Document::extracted_text. The span text itself is never duplicated here. */
class DocumentSourceSpan extends Model
{
    use HasUuids;

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['created_at' => 'datetime'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
