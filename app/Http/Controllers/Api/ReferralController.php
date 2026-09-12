<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ReferralService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReferralController extends Controller
{
    public function myCode(Request $request, ReferralService $referrals): JsonResponse
    {
        $code = $referrals->codeFor($request->user());
        $summary = $code->referrals()->selectRaw(
            "COUNT(*) AS signups, COUNT(CASE WHEN status = 'rewarded' THEN 1 END) AS rewarded, COALESCE(SUM(reward_documents), 0) AS credits_earned"
        )->first();

        return $this->success('Referral code retrieved.', [
            'code' => $code->code,
            'link' => rtrim(config('app.frontend_url'), '/').'/#/signup?referral_code='.rawurlencode($code->code),
            'reward_documents' => (int) config('credits.referral_reward_documents'),
            'summary' => [
                'signups' => (int) $summary->signups,
                'rewarded' => (int) $summary->rewarded,
                'credits_earned' => (int) $summary->credits_earned,
            ],
        ]);
    }
}
