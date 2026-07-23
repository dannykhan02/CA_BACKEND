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

    // Ordering by id (not sort_order — column doesn't exist) preserves
    // insertion order since id is an auto-increment PK and rows are
    // always seeded/created in display order.
    public function kpis()
    {
        return $this->hasMany(DocumentKpi::class)->orderBy('id');
    }

    public function charts()
    {
        return $this->hasMany(DocumentChart::class)->orderBy('id');
    }

    public function pageFlags()
    {
        return $this->hasMany(DocumentPageFlag::class)->orderBy('page');
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