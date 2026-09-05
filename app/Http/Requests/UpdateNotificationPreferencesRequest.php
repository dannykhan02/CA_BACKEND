<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateNotificationPreferencesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'processing_complete' => ['required', 'boolean'],
            'review_requested' => ['required', 'boolean'],
            'power_bi_sync' => ['required', 'boolean'],
        ];
    }
}
