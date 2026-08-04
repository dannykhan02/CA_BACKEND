<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceSetting extends Model
{
    protected $primaryKey = 'workspace_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'ai_provider',
        'theme',
        'language',
        'timezone',
        'powerbi_enabled',
        'ocr_enabled',
        'handwriting_enabled',
        'default_classification',
    ];

    protected $casts = [
        'powerbi_enabled' => 'boolean',
        'ocr_enabled' => 'boolean',
        'handwriting_enabled' => 'boolean',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
