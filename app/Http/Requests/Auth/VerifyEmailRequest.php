<?php

namespace App\Http\Requests\Auth;

class VerifyEmailRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
            ],

            'code' => [
                'required',
                'digits:6',
            ],
        ];
    }
}