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
        return array_merge(parent::rules(), [
            'citations' => ['present', 'array', 'max:50'],
            'version' => ['required', 'integer', 'min:1'],
        ]);
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
