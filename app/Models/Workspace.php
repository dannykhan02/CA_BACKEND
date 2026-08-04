<?php

namespace App\Models;

use App\Enums\WorkspaceType; // ✅ added import
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

class Workspace extends Model
{
    use HasUuids;

    protected $fillable = ['type', 'name'];

    // ✅ added casts to convert type to WorkspaceType enum
    protected $casts = [
        'type' => WorkspaceType::class,
    ];

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