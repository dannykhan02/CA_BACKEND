<?php

namespace App\Http\Requests\Auth;

class GoogleSigninRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'fingerprint' => ['nullable', 'string', 'max:255'],
            'id_token' => [
                'required',
                'string',
            ],
        ];
    }
}
