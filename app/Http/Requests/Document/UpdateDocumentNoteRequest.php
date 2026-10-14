<?php

namespace App\Http\Requests\Document;

use App\Services\DocumentNoteAccess;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateDocumentNoteRequest extends StoreDocumentNoteRequest
{
    public function authorize(): bool
    {
        $access = app(DocumentNoteAccess::class);
        $document = $access->document($this->user(), (string) $this->route('document'));
        $access->note($this->user(), $document, (string) $this->route('note'));

        return true;
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['body'] = ['sometimes', 'required', 'string', 'min:1', 'max:20000', 'not_regex:/^\s*$/u'];
        $rules['content'] = ['sometimes', 'nullable', 'array'];
        $rules['contentSchemaVersion'] = ['sometimes', 'nullable', 'integer', 'in:1'];
        $rules['version'] = ['required', 'integer', 'min:1'];

        return $rules;
    }

    protected function failedValidation(Validator $validator): void
    {
        if (isset($validator->failed()['version'])) {
            throw new HttpResponseException(response()->json([
                'success' => false, 'message' => 'The given data was invalid.',
                'errors' => $validator->errors()->toArray(),
                'error' => ['code' => 'NOTE_VERSION_REQUIRED'],
            ], 422));
        }
        parent::failedValidation($validator);
    }
}
