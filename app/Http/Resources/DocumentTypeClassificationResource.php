<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentTypeClassificationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'documentType' => $this->document_type,
            'confidence' => (float) $this->confidence,
            'reasoning' => $this->reasoning,
            'promptVersion' => $this->prompt_version,
        ];
    }
}
