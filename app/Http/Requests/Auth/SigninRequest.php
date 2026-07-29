<?php

namespace App\Http\Requests\Auth;

class SigninRequest extends BaseAuthRequest
{
    public function rules(): array
    {
        return [
            'email' => [
                'required',
                'email',
                'max:255',
            ],

            'password' => [
                'required',
                'string',
            ],

            'remember_me' => [
                'sometimes',
                'boolean',
            ],
        ];
    }
}