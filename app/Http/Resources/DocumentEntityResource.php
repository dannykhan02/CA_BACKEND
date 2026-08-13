<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentEntityResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'entityType' => $this->entity_type,
            'value' => $this->value,
            'normalizedValue' => $this->normalized_value,
            'confidence' => (float) $this->confidence,
            'context' => $this->context,
            'promptVersion' => $this->prompt_version,
        ];
    }
}
