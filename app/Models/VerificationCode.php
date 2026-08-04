<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class VerificationCode extends Model
{
    protected $fillable = ['user_id', 'code', 'expires_at'];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    /**
     * Hardening addition (not in the original audit, found on re-review):
     * the `code` column previously stored the raw 6-digit OTP in plaintext,
     * looked up directly via ->where('code', $input). Anyone with read
     * access to the verification_codes table (a DB backup, a compromised
     * read replica, a misconfigured admin tool) could read live OTPs
     * directly. Laravel's own password-reset broker already hashes its
     * tokens at rest — this brings email verification codes to the same
     * standard.
     *
     * $plainCode is a transient, non-persisted property: it exists only on
     * the instance returned by generateFor(), for the caller to email out.
     * It is never written to the database and is not in $fillable.
     */
    public ?string $plainCode = null;

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public static function generateFor(User $user): self
    {
        // clear any outstanding codes so only the latest is valid
        static::where('user_id', $user->id)->delete();

        $plainCode = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

        $record = static::create([
            'user_id' => $user->id,
            'code' => Hash::make($plainCode),
            'expires_at' => now()->addMinutes(15),
        ]);

        $record->plainCode = $plainCode;

        return $record;
    }
}
