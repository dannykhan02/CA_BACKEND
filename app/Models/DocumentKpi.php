<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentKpi extends Model
{
    protected $fillable = [
        'document_id',
        'workspace_id',
        'kpi_definition_id',
        'identity_metadata',
        'period',
        'label',
        'value',
        'value_numeric',
        'unit',
        'trend',
        'trend_value',
    ];

    protected $casts = [
        'value_numeric' => 'decimal:4',
        'identity_metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::saving(function (self $kpi) {
            if ($kpi->kpi_definition_id !== null && (
                ! $kpi->workspace_id
                || ! KpiDefinition::where('workspace_id', $kpi->workspace_id)->whereKey($kpi->kpi_definition_id)->exists()
                || ! Document::withTrashed()->whereKey($kpi->document_id)->where('workspace_id', $kpi->workspace_id)->exists()
            )) {
                throw new \LogicException('KPI identity and observation must belong to the document workspace.');
            }
        });
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function definition()
    {
        return $this->belongsTo(KpiDefinition::class, 'kpi_definition_id');
    }

    /** Keep the two legacy normalization contracts at their existing callers. */
    public function identityKey(string $fallbackLabel): string
    {
        return $this->kpi_definition_id ? 'definition:'.$this->kpi_definition_id : 'label:'.$fallbackLabel;
    }
}
