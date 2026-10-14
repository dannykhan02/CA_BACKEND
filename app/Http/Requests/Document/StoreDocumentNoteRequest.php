<?php

namespace App\Http\Requests\Document;

use App\Services\DocumentNoteAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreDocumentNoteRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(DocumentNoteAccess::class)->document($this->user(), (string) $this->route('document'));

        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:1', 'max:20000', 'not_regex:/^\s*$/u'],
            'citations' => ['sometimes', 'array', 'max:50'],
            'citations.*' => ['required', 'array:finding_label,quote_snapshot,page,intelligence_revision_id,source_id'],
            'citations.*.finding_label' => ['required', 'string', 'min:1', 'max:200'],
            'citations.*.quote_snapshot' => ['required', 'string', 'min:1', 'max:1000'],
            'citations.*.page' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'citations.*.intelligence_revision_id' => ['nullable', 'uuid'],
            'citations.*.source_id' => ['nullable', 'string', 'max:200'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        $failed = $validator->failed();
        $code = isset($failed['body']) ? 'NOTE_INVALID_BODY' : 'INVALID_CITATION';
        if (isset($failed['body']['Max'])) {
            $code = 'NOTE_TOO_LONG';
        } elseif (isset($failed['citations']['Max'])) {
            $code = 'TOO_MANY_CITATIONS';
        }

        throw new HttpResponseException(response()->json([
            'success' => false, 'message' => 'The given data was invalid.',
            'errors' => $validator->errors()->toArray(), 'error' => ['code' => $code],
        ], 422));
    }
}
