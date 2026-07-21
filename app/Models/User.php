<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Notifications\ResetPasswordNotification;

class User extends Authenticatable
{
    use HasApiTokens, HasUuids, Notifiable;

    public function sendPasswordResetNotification($token): void
    {
        $frontendUrl = rtrim(env('FRONTEND_URL', 'http://localhost:5173'), '/');
        $url = "{$frontendUrl}/reset-password?token={$token}&email=" . urlencode($this->email);

        $this->notify(new ResetPasswordNotification($url));
    }


    protected $fillable = [
        'email', 'password', 'full_name', 'role', 'active',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_active_at' => 'datetime',
        'active' => 'boolean',
        'password' => 'hashed',
    ];

    public function verificationCodes()
    {
        return $this->hasMany(VerificationCode::class);
    }

    public function uploadedDocuments()
    {
        return $this->hasMany(Document::class, 'uploaded_by');
    }
}