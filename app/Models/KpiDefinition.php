<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class KpiDefinition extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'canonical_name', 'normalized_name', 'concept', 'scope',
        'metric_type', 'unit', 'matching_metadata', 'identity_key',
    ];

    protected $casts = ['matching_metadata' => 'array'];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function aliases()
    {
        return $this->hasMany(KpiAlias::class);
    }

    public function observations()
    {
        return $this->hasMany(DocumentKpi::class);
    }
}
