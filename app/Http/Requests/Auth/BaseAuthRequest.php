<?php

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseAuthRequest extends FormRequest
{
    /**
     * All authentication requests are public.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize email before validation.
     *
     * Ensures every authentication endpoint treats:
     *
     * John@Example.com
     * JOHN@example.com
     * john@example.com
     *
     * as the same email.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('email')) {
            $this->merge([
                'email' => mb_strtolower(
                    trim($this->input('email'))
                ),
            ]);
        }
    }
}