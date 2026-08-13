<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentDeadlineResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'deadlineType' => $this->deadline_type,
            'title' => $this->title,
            'description' => $this->description,
            'dueDate' => $this->due_date?->toDateString(),
            'dateType' => $this->date_type,
            'relativeText' => $this->relative_text,
            'confidence' => (float) $this->confidence,
            'evidence' => $this->evidence,
            'status' => $this->status,
            'promptVersion' => $this->prompt_version,
        ];
    }
}
