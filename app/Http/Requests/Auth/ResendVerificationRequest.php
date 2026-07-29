<?php

namespace App\Http\Requests\Auth;

class ResendVerificationRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
            ],
        ];
    }
}