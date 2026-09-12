<?php

namespace App\Services;

use App\Models\Referral;
use App\Models\ReferralCode;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReferralService
{
    public function codeFor(User $user): ReferralCode
    {
        // Both owner and code have unique indexes. insertOrIgnore handles
        // simultaneous first requests and the unlikely random-code collision
        // without aborting a surrounding PostgreSQL transaction.
        while (! $code = ReferralCode::where('user_id', $user->id)->first()) {
            DB::table('referral_codes')->insertOrIgnore([
                'user_id' => $user->id,
                'code' => Str::random(8),
                'created_at' => now(),
            ]);
        }

        return $code;
    }

    /** Called inside signup's transaction, using the actual trial-grant result. */
    public function recordSignup(User $user, ?string $code, bool $trialGranted): void
    {
        if (! $code) {
            return;
        }
        $referralCode = ReferralCode::with('user')->where('code', $code)->first();
        if (! $referralCode || ! $referralCode->user
            || $referralCode->user_id === $user->id
            || mb_strtolower(trim($referralCode->user->email)) === mb_strtolower(trim($user->email))) {
            return;
        }

        Referral::firstOrCreate(['referred_user_id' => $user->id], [
            'referral_code_id' => $referralCode->id,
            'status' => 'pending',
            'reward_eligible' => $trialGranted,
            'ineligible_reason' => $trialGranted ? null : 'trial_abuse_signal_match',
        ]);
    }
}
