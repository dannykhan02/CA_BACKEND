<?php

namespace App\Http\Requests\Auth;

class GoogleSigninRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'accepted_terms' => ['sometimes', 'accepted'],
            'terms_version' => ['sometimes', 'in:'.config('legal.version')],
            'referral_code' => ['nullable', 'string', 'max:255'],
            'fingerprint' => ['nullable', 'string', 'max:255'],
            'id_token' => [
                'required',
                'string',
            ],
        ];
    }
}
