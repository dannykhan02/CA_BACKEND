<?php

namespace App\Http\Requests\Auth;

use Illuminate\Validation\Rules\Password;

class SignupRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'accepted_terms' => ['required', 'accepted'],
            'terms_version' => ['required', 'in:'.config('legal.version')],
            'referral_code' => ['nullable', 'string', 'max:255'],
            'fingerprint' => ['nullable', 'string', 'max:255'],
            'full_name' => [
                'required',
                'string',
                'max:255',
            ],

            'email' => [
                'required',
                'email',
                'max:255',
                'unique:users,email',
            ],

            'password' => [
                'required',
                'confirmed',
                Password::min(8)
                    ->mixedCase()
                    ->numbers()
                    ->symbols(),
            ],
        ];
    }
}
