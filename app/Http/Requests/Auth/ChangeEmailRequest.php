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

    /**
     * Audit VAL-1: normalize case before the uniqueness check runs. Not
     * exploitable in practice — User::email's mutator lowercases on save,
     * and confirmEmailChange() already catches the resulting
     * unique-constraint race with a clean 409 — but this closes the gap at
     * the validation layer too, matching every other email-accepting
     * request in the app.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('new_email')) {
            $this->merge([
                'new_email' => mb_strtolower(trim((string) $this->input('new_email'))),
            ]);
        }
    }
}
