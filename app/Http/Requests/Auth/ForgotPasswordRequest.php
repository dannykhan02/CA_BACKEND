<?php

namespace App\Http\Requests\Auth;

class ForgotPasswordRequest extends BaseAuthRequest
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