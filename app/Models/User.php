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
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_active_at' => 'datetime',
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
}