<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use HasUuids;
    use SoftDeletes;

    protected $fillable = [
        'matter_id', 'name', 'type', 'size_kb', 'status', 'classification', 'year',
        'workspace_id', 'uploaded_by', 'last_updated_by', 'pages',
        'has_structured_data', 'progress', 'error_message',
        'power_bi_status', 'insights', 'file_path', 'file_hash',
        'extraction_attempts', 'extraction_started_at',
        'extraction_completed_at', 'extraction_input_tokens',
        'extraction_output_tokens', 'extracted_text',
    ];

    protected $casts = [
        'intelligence_generation' => 'integer',
        'ai_pipeline' => 'array',
        'has_structured_data' => 'boolean',
        'insights' => 'array',
        'extraction_started_at' => 'datetime',
        'extraction_completed_at' => 'datetime',
    ];

    /**
     * Is a storage key recorded for this document's original file?
     *
     * This answers *only* whether a path is written on the row. It never
     * touches the disk, so it must not be read as "the bytes are there" —
     * a recorded key whose object is missing still answers true, and that
     * case is a storage anomaly worth alerting on (see
     * `documents:audit-originals` and the 2026-10-17 source-viewer incident),
     * not the same thing as a row that never had a file at all.
     *
     * `file_path` is nullable (`create_documents_table`), and production
     * carries rows with no key: seeded documents for which nothing was ever
     * uploaded. Every caller that has to tell those apart from real documents
     * goes through this one method so the download guard, the API resources
     * and the audit command cannot drift into disagreeing about what "has an
     * original" means. Whitespace counts as absent: a key of " " addresses
     * nothing, and treating it as present only moves the failure to the disk.
     */
    public function hasRecordedOriginal(): bool
    {
        return is_string($this->file_path) && trim($this->file_path) !== '';
    }

    /** Structured KPI matching can use existing observations without the source text. */
    public function canGenerateIntelligence(bool $requiresExtractedText = true): bool
    {
        return ! $this->trashed()
            && in_array($this->status, ['Processing', 'Ready'], true)
            && (! $requiresExtractedText || trim((string) $this->extracted_text) !== '');
    }

    public function matter()
    {
        return $this->belongsTo(Matter::class);
    }

    // ✅ Fixed: method name and parameter list
    public function kpis()
    {
        return $this->hasMany(DocumentKpi::class)->where('intelligence_revision_id', $this->intelligenceRevisionForRelations())->orderBy('id');
    }

    public function charts()
    {
        return $this->hasMany(DocumentChart::class)->where('intelligence_revision_id', $this->intelligenceRevisionForRelations())->orderBy('id');
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

    public function processingChunks()
    {
        return $this->hasMany(DocumentChunk::class);
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
        return $this->hasOne(DocumentTypeClassification::class)->where('intelligence_revision_id', $this->intelligenceRevisionForRelations());
    }

    public function entities()
    {
        return $this->hasMany(DocumentEntity::class)->where('intelligence_revision_id', $this->intelligenceRevisionForRelations());
    }

    public function risks()
    {
        return $this->hasMany(DocumentRisk::class)->where('intelligence_revision_id', $this->intelligenceRevisionForRelations());
    }

    public function deadlines()
    {
        return $this->hasMany(DocumentDeadline::class)->where('intelligence_revision_id', $this->intelligenceRevisionForRelations());
    }

    public function intelligenceSummary()
    {
        return $this->hasOne(DocumentIntelligenceSummary::class)->where('intelligence_revision_id', $this->intelligenceRevisionForRelations());
    }

    /** Explicit read pin wins over the candidate writer pointer. Null identifies legacy rows. */
    public function intelligenceRevisionForRelations(): ?string
    {
        if (array_key_exists('read_intelligence_revision_id', $this->attributes)) {
            return $this->attributes['read_intelligence_revision_id'];
        }

        return $this->active_intelligence_revision_id ?? $this->published_intelligence_revision_id;
    }

    public function publishedIntelligenceRevision()
    {
        return $this->belongsTo(DocumentIntelligenceRevision::class, 'published_intelligence_revision_id');
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
