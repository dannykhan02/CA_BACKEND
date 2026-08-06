<?php

namespace App\Http\Requests\Document;

use App\Enums\WorkspaceType;
use App\Services\Documents\SupportedDocumentTypes;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UploadDocumentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $workspace = $this->user()->currentWorkspace;

        if ($workspace?->type === WorkspaceType::Personal) {
            // Sole member of their own workspace — no reviewer/administrator
            // concept exists to gate against.
            return true;
        }

        // Organization workspace: unchanged Administrator/Reviewer-only rule.
        return in_array($this->user()->role, ['Administrator', 'Reviewer'], true);
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $maxKb = config('document_processing.max_upload_size_kb');
        $supportedTypes = app(SupportedDocumentTypes::class);

        return [
            'file' => [
                'required',
                'file',
                'max:' . $maxKb,
                // Single source of truth now — extensions and MIME types both
                // come from config/document_types.php via SupportedDocumentTypes,
                // not a hardcoded list here plus a separate document_processing
                // config key. Only currently-enabled types are accepted.
                'extensions:' . implode(',', $supportedTypes->enabledExtensions()),
                'mimetypes:' . implode(',', $supportedTypes->enabledMimeTypes()),
            ],
            // Matches the exact documents_classification_check DB constraint.
            'classification' => ['required', Rule::in(['Public', 'Internal', 'Confidential', 'Restricted'])],
        ];
    }

    /**
     * Get the custom error messages for validation failures.
     */
    public function messages(): array
    {
        return [
            'file.max' => 'File exceeds the maximum allowed size of ' . round(config('document_processing.max_upload_size_kb') / 1024) . 'MB.',
            'file.mimetypes' => 'File type not recognized as a genuine PDF or DOCX — the file may be corrupted, mislabeled, or a disguised file type.',
        ];
    }
}