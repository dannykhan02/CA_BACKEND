<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'type', 'size_kb', 'status', 'classification', 'year',
        'uploaded_by', 'last_updated_by', 'pages', 'has_structured_data',
        'progress', 'error_message', 'power_bi_status', 'insights',
        'file_path', 'file_hash',
    ];

    protected $casts = [
        'has_structured_data' => 'boolean',
        'insights' => 'array',
    ];

    public function kpis()
    {
        return $this->hasMany(DocumentKpi::class);
    }

    public function charts()
    {
        return $this->hasMany(DocumentChart::class);
    }

    public function pageFlags()
    {
        return $this->hasMany(DocumentPageFlag::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function lastUpdater()
    {
        return $this->belongsTo(User::class, 'last_updated_by');
    }
}