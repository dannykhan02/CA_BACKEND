<?php

namespace App\Http\Requests\Auth;

class GoogleSigninRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'id_token' => [
                'required',
                'string',
            ],
        ];
    }
}