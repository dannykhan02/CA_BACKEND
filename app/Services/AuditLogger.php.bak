<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Str;

class AuditLogger
{
    /**
     * @param  string  $action  dot.namespaced, e.g. 'document.download'
     * @param  array<string, mixed>  $metadata  small, non-sensitive context only
     *         — never log full document contents, insights, or credentials here.
     */
    public function log(?User $user, string $action, Model $subject, array $metadata = []): void
    {
        AuditLog::create([
            'id' => (string) Str::uuid(),
            'user_id' => $user?->id,
            'action' => $action,
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'metadata' => $metadata,
            'ip_address' => Request::ip(),
            'created_at' => now(),
        ]);
    }
}
