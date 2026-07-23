<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;

class IndexDocumentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // gated by auth:sanctum middleware at the route level
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'array'],
            'status.*' => ['string', 'in:Processing,Ready,Needs Review,Failed'],
            'classification' => ['nullable', 'array'],
            'classification.*' => ['string', 'in:Public,Internal,Confidential,Restricted'],
            'year' => ['nullable', 'integer', 'min:2000', 'max:2100'],
            'author' => ['nullable', 'string', 'max:255'],
            'sort_by' => ['nullable', 'string', 'in:uploaded_at,name,size_kb,year,status,pages'],
            'sort_dir' => ['nullable', 'string', 'in:asc,desc'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    protected function prepareForValidation(): void
    {
        foreach (['status', 'classification'] as $key) {
            $value = $this->query($key);
            if (is_string($value)) {
                $this->merge([$key => array_filter(array_map('trim', explode(',', $value)))]);
            }
        }
    }
}
