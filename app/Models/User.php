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
