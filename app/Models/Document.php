<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    use HasUuids;

    protected $fillable = [
        'name', 'type', 'size_kb', 'status', 'classification', 'year',
        'workspace_id', 'uploaded_by', 'last_updated_by', 'pages',
        'has_structured_data', 'progress', 'error_message',
        'power_bi_status', 'insights', 'file_path', 'file_hash',
        'extraction_attempts', 'extraction_started_at',
        'extraction_completed_at', 'extraction_input_tokens',
        'extraction_output_tokens', 'extracted_text',
    ];

    protected $casts = [
        'has_structured_data' => 'boolean',
        'insights' => 'array',
        'extraction_started_at' => 'datetime',
        'extraction_completed_at' => 'datetime',
    ];

    // ✅ Fixed: method name and parameter list
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

    public function versions()
    {
        return $this->hasMany(DocumentVersion::class)->orderBy('version_number');
    }

    public function ocrResults()
    {
        return $this->hasMany(OcrResult::class)->orderBy('page_number');
    }

    public function processingJobs()
    {
        return $this->hasMany(ProcessingJob::class);
    }

    /**
     * AI-derived document TYPE (compliance_report, financial_report, etc.) —
     * distinct from the 'classification' attribute above, which is an
     * unrelated security/access-control field (Public/Internal/Confidential/
     * Restricted). Deliberately named documentTypeClassification(), not
     * classification(), to avoid any collision with that existing attribute.
     */
    public function documentTypeClassification()
    {
        return $this->hasOne(DocumentTypeClassification::class);
    }

    public function entities()
    {
        return $this->hasMany(DocumentEntity::class);
    }

    public function risks()
    {
        return $this->hasMany(DocumentRisk::class);
    }

    public function deadlines()
    {
        return $this->hasMany(DocumentDeadline::class);
    }

    public function intelligenceSummary()
    {
        return $this->hasOne(DocumentIntelligenceSummary::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
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