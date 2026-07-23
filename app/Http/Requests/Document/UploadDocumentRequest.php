<?php

namespace App\Http\Requests\Document;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Matches confirmed roles: Administrator, Reviewer, Analyst, Viewer.
        // Only Administrator/Reviewer can upload — Analyst/Viewer are read-only
        // for now; Day 6's policy layer can refine this further if needed.
        return in_array($this->user()->role, ['Administrator', 'Reviewer'], true);
    }

    public function rules(): array
    {
        $maxKb = config('document_processing.max_upload_size_kb');

        return [
            'file' => [
                'required',
                'file',
                'max:' . $maxKb,
                'mimes:pdf,docx',
                'mimetypes:' . implode(',', config('document_processing.allowed_mimes')),
            ],
            // Matches the exact documents_classification_check DB constraint.
            'classification' => ['required', Rule::in(['Public', 'Internal', 'Confidential', 'Restricted'])],
        ];
    }

    public function messages(): array
    {
        return [
            'file.max' => 'File exceeds the maximum allowed size of ' . round(config('document_processing.max_upload_size_kb') / 1024) . 'MB.',
            'file.mimetypes' => 'File type not recognized as a genuine PDF or DOCX — the file may be corrupted, mislabeled, or a disguised file type.',
        ];
    }
}
