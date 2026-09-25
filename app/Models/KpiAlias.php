<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KpiAlias extends Model
{
    protected $fillable = [
        'workspace_id', 'kpi_definition_id', 'label', 'normalized_label',
        'context_key', 'matching_metadata', 'method',
    ];

    protected $casts = ['matching_metadata' => 'array'];

    public function definition()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
