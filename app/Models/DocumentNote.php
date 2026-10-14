<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DocumentNote extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = ['document_id', 'workspace_id', 'user_id', 'body', 'version'];

    protected $attributes = ['content_format' => 'plain_text'];

    protected $casts = ['version' => 'integer', 'content' => 'array'];

    public function citations()
    {
        return $this->hasMany(DocumentNoteCitation::class, 'note_id')->orderBy('created_at')->orderBy('id');
    }
}
