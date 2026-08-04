#!/usr/bin/env bash
#
# Creates the models missing for workspaces/workspace_members/
# workspace_settings/workspace_ai_configs/processing_jobs/ocr_results/
# document_versions, and overwrites the existing 7 models so their
# $fillable/casts/relationships match the actual current schema
# (workspace_id columns, current_workspace_id on users, etc).
#
# Existing model files not listed here (App\Models\*) that you didn't
# share aren't touched. Back up app/Models first if you've made manual
# edits beyond what was in your dump.
#
# Usage:
#   cd /path/to/your/laravel/backend
#   bash align_models.sh
#
set -euo pipefail

if [ ! -d "app/Models" ]; then
  echo "app/Models not found — run this from your Laravel project root."
  exit 1
fi

MODELS="app/Models"

# ---------------------------------------------------------------------
# NEW: Workspace
# ---------------------------------------------------------------------
cat > "$MODELS/Workspace.php" << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use HasUuids;

    protected $fillable = ['type', 'name'];

    public function members()
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function users()
    {
        return $this->belongsToMany(User::class, 'workspace_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }

    public function settings()
    {
        return $this->hasOne(WorkspaceSetting::class);
    }

    public function aiConfig()
    {
        return $this->hasOne(WorkspaceAiConfig::class);
    }

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    public function processingJobs()
    {
        return $this->hasMany(ProcessingJob::class);
    }

    public function auditLogs()
    {
        return $this->hasMany(AuditLog::class);
    }
}
EOF

# ---------------------------------------------------------------------
# NEW: WorkspaceMember
# ---------------------------------------------------------------------
cat > "$MODELS/WorkspaceMember.php" << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class WorkspaceMember extends Model
{
    use HasUuids;

    protected $fillable = ['workspace_id', 'user_id', 'role', 'joined_at'];

    protected $casts = [
        'joined_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
EOF

# ---------------------------------------------------------------------
# NEW: WorkspaceSetting  (primary key IS workspace_id — no surrogate id)
# ---------------------------------------------------------------------
cat > "$MODELS/WorkspaceSetting.php" << 'EOF'
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
EOF

# ---------------------------------------------------------------------
# NEW: WorkspaceAiConfig  (primary key IS workspace_id — no surrogate id)
# ---------------------------------------------------------------------
cat > "$MODELS/WorkspaceAiConfig.php" << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkspaceAiConfig extends Model
{
    protected $primaryKey = 'workspace_id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'workspace_id',
        'provider',
        'model',
        'temperature',
        'api_key_encrypted',
        'max_tokens',
        'vision_enabled',
    ];

    protected $casts = [
        // Laravel encrypts/decrypts this transparently on every read/write.
        // Never read $attributes['api_key_encrypted'] directly, and never
        // log this model's full attribute array.
        'api_key_encrypted' => 'encrypted',
        'temperature' => 'decimal:2',
        'vision_enabled' => 'boolean',
    ];

    protected $hidden = [
        'api_key_encrypted',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
EOF

# ---------------------------------------------------------------------
# NEW: ProcessingJob
# ---------------------------------------------------------------------
cat > "$MODELS/ProcessingJob.php" << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class ProcessingJob extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'document_id', 'stage', 'status',
        'input', 'output', 'error_message',
        'started_at', 'completed_at',
    ];

    protected $casts = [
        'input' => 'array',
        'output' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
EOF

# ---------------------------------------------------------------------
# NEW: OcrResult
# ---------------------------------------------------------------------
cat > "$MODELS/OcrResult.php" << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class OcrResult extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'document_id', 'page_number',
        'engine', 'raw_text', 'confidence', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'confidence' => 'decimal:4',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }
}
EOF

# ---------------------------------------------------------------------
# NEW: DocumentVersion
# ---------------------------------------------------------------------
cat > "$MODELS/DocumentVersion.php" << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class DocumentVersion extends Model
{
    use HasUuids;

    protected $fillable = [
        'workspace_id', 'document_id', 'version_number',
        'file_path', 'file_hash', 'created_by', 'change_note',
    ];

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
EOF

# ---------------------------------------------------------------------
# UPDATED: Document  (+ workspace_id, + workspace/versions/ocrResults/jobs)
# ---------------------------------------------------------------------
cat > "$MODELS/Document.php" << 'EOF'
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
        'extraction_output_tokens',
    ];

    protected $casts = [
        'has_structured_data' => 'boolean',
        'insights' => 'array',
        'extraction_started_at' => 'datetime',
        'extraction_completed_at' => 'datetime',
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
EOF

# ---------------------------------------------------------------------
# UPDATED: DocumentKpi  (+ workspace_id)
# ---------------------------------------------------------------------
cat > "$MODELS/DocumentKpi.php" << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentKpi extends Model
{
    protected $fillable = [
        'document_id',
        'workspace_id',
        'label',
        'value',
        'value_numeric',
        'unit',
        'trend',
        'trend_value',
    ];

    protected $casts = [
        'value_numeric' => 'decimal:4',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
EOF

# ---------------------------------------------------------------------
# UPDATED: DocumentChart  (+ workspace_id)
# ---------------------------------------------------------------------
cat > "$MODELS/DocumentChart.php" << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentChart extends Model
{
    protected $fillable = ['document_id', 'workspace_id', 'type', 'title', 'description', 'data'];

    protected $casts = [
        'data' => 'array',
    ];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    // Normalized rows for the Power BI reporting layer — kept alongside the
    // existing `data` JSON column rather than replacing it, since the app's
    // own frontend chart rendering reads `data` directly and shouldn't need
    // to change. `points` exists purely so BI/reporting tools can query
    // real rows instead of unpacking JSON, which most BI connectors handle
    // poorly or not at all.
    public function points()
    {
        return $this->hasMany(DocumentChartPoint::class)->orderBy('sort_order');
    }
}
EOF

# ---------------------------------------------------------------------
# UPDATED: DocumentChartPoint  (+ workspace_id)
# ---------------------------------------------------------------------
cat > "$MODELS/DocumentChartPoint.php" << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentChartPoint extends Model
{
    protected $fillable = ['document_chart_id', 'workspace_id', 'label', 'value', 'sort_order'];

    protected $casts = [
        'value' => 'decimal:4',
    ];

    public function chart()
    {
        return $this->belongsTo(DocumentChart::class, 'document_chart_id');
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
EOF

# ---------------------------------------------------------------------
# UPDATED: DocumentPageFlag  (+ workspace_id)
# ---------------------------------------------------------------------
cat > "$MODELS/DocumentPageFlag.php" << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentPageFlag extends Model
{
    protected $fillable = ['document_id', 'workspace_id', 'page', 'status', 'note'];

    public function document()
    {
        return $this->belongsTo(Document::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }
}
EOF

# ---------------------------------------------------------------------
# UPDATED: AuditLog  (+ workspace_id)
# ---------------------------------------------------------------------
cat > "$MODELS/AuditLog.php" << 'EOF'
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasUuids;

    // Append-only: no updated_at column, and application code should never
    // update or delete a row here outside of a documented retention policy.
    public $timestamps = false;

    protected $fillable = [
        'user_id', 'workspace_id', 'action', 'auditable_type',
        'auditable_id', 'metadata', 'ip_address', 'created_at',
    ];

    protected $casts = [
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function workspace()
    {
        return $this->belongsTo(Workspace::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
EOF

# ---------------------------------------------------------------------
# UPDATED: User  (+ current_workspace_id, + workspace relations)
# ---------------------------------------------------------------------
cat > "$MODELS/User.php" << 'EOF'
<?php

namespace App\Models;

use App\Notifications\ResetPasswordNotification;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasUuids;
    use Notifiable;

    protected $fillable = [
        'email',
        'password',
        'full_name',
        'role',
        'active',
        'current_workspace_id',
        'pending_email',
        'pending_email_code',
        'pending_email_expires_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_active_at' => 'datetime',
        'pending_email_expires_at' => 'datetime',
        'active' => 'boolean',
        'password' => 'hashed',
    ];

    /**
     * Normalize email before every write.
     *
     * Prevents:
     *
     * John@Example.com
     * JOHN@example.com
     * john@example.com
     *
     * from ever becoming separate accounts.
     */
    protected function email(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => $value === null
                ? null
                : mb_strtolower(trim($value))
        );
    }

    /**
     * Framework hook: fires only if something triggers Laravel's built-in
     * password-broker flow directly (Password::sendResetLink(), etc.)
     * independently of AuthController::forgotPassword(), which instead
     * calls Password::broker()->createToken() and sends
     * App\Notifications\PasswordResetNotification itself. Both paths build
     * an identical reset URL, so behavior is consistent either way — this
     * is intentional duplication, not drift. Audit F-Low-4: don't
     * "consolidate" these into one without confirming which paths are
     * actually reachable in this app.
     */
    public function sendPasswordResetNotification($token): void
    {
        $frontendUrl = rtrim(
            config('app.frontend_url', 'http://localhost:5173'),
            '/'
        );

        $url = "{$frontendUrl}/#/reset?token={$token}&email=" . urlencode($this->email);

        $this->notify(new ResetPasswordNotification($url));
    }

    public function verificationCodes()
    {
        return $this->hasMany(VerificationCode::class);
    }

    public function uploadedDocuments()
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }

    public function currentWorkspace()
    {
        return $this->belongsTo(Workspace::class, 'current_workspace_id');
    }

    public function workspaceMemberships()
    {
        return $this->hasMany(WorkspaceMember::class);
    }

    public function workspaces()
    {
        return $this->belongsToMany(Workspace::class, 'workspace_members')
            ->withPivot(['role', 'joined_at'])
            ->withTimestamps();
    }
}
EOF

echo
echo "Models written/updated in $MODELS:"
echo "  NEW:     Workspace, WorkspaceMember, WorkspaceSetting, WorkspaceAiConfig,"
echo "           ProcessingJob, OcrResult, DocumentVersion"
echo "  UPDATED: Document, DocumentKpi, DocumentChart, DocumentChartPoint,"
echo "           DocumentPageFlag, AuditLog, User"
echo
echo "Not touched (weren't in your dump): VerificationCode"