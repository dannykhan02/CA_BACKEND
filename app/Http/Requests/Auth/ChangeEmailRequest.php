<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChangeEmailRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'new_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'current_password' => ['required', 'string'],
        ];
    }
}
