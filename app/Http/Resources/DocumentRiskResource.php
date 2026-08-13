<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentRiskResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'riskType' => $this->risk_type,
            'title' => $this->title,
            'description' => $this->description,
            'severity' => $this->severity,
            'confidence' => (float) $this->confidence,
            'evidence' => $this->evidence,
            'status' => $this->status,
            'promptVersion' => $this->prompt_version,
        ];
    }
}
