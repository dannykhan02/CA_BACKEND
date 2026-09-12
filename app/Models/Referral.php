<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Referral extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'referral_code_id', 'referred_user_id', 'status', 'reward_eligible',
        'ineligible_reason', 'reward_documents', 'rewarded_workspace_id', 'rewarded_at',
    ];

    protected $casts = [
        'reward_eligible' => 'boolean',
        'reward_documents' => 'integer',
        'rewarded_at' => 'datetime',
    ];

    public function referralCode()
    {
        return $this->belongsTo(ReferralCode::class);
    }
}
