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
            'appendReferences' => ['sometimes', 'array', 'max:50'],
            'appendReferences.*' => ['required', 'array:intelligence_revision_id,reference_type,finding_id'],
            'appendReferences.*.intelligence_revision_id' => ['required', 'uuid'],
            'appendReferences.*.reference_type' => ['required', 'in:finding'],
            'appendReferences.*.finding_id' => ['required', 'string', 'max:200'],
            'removeReferenceIds' => ['sometimes', 'array', 'max:50'],
            'removeReferenceIds.*' => ['required', 'uuid', 'distinct'],
            'body' => ['required_without:content', 'string', 'min:1', 'max:20000', 'not_regex:/^\s*$/u'],
            'content' => ['sometimes', 'array'],
            'contentSchemaVersion' => ['required_with:content', 'integer', 'in:1'],
            'citations' => ['sometimes', 'array', 'max:50'],
            'citations.*' => ['required', 'array:finding_label,quote_snapshot,page,intelligence_revision_id,source_id,client_ref'],
            'citations.*.client_ref' => ['sometimes', 'string', 'regex:/^draft:[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', 'distinct'],
            'citations.*.finding_label' => ['required', 'string', 'min:1', 'max:200'],
            'citations.*.quote_snapshot' => ['required', 'string', 'min:1', 'max:1000'],
            'citations.*.page' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'citations.*.intelligence_revision_id' => ['nullable', 'uuid'],
            'citations.*.source_id' => ['nullable', 'string', 'max:200'],
        ];
    }

    protected function withValidator(Validator $validator): void
    {
        // Include citation drafts and JSON escaping overhead in the wire limit.
        // Authorization has already run before FormRequest builds this validator.
        $validator->after(function (Validator $validator): void {
            if (array_key_exists('content', $this->all()) && strlen($this->getContent()) > 262144) {
                $validator->errors()->add('content', 'Rich note request is too large.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        $failed = $validator->failed();
        $code = isset($failed['body']) ? 'NOTE_INVALID_BODY' : 'INVALID_CITATION';
        if ($validator->errors()->has('content') || isset($failed['contentSchemaVersion'])) {
            $code = 'NOTE_INVALID_CONTENT';
        }
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
