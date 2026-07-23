<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KpiResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'label' => $this->label,
            'value' => $this->value,
            'unit' => $this->unit,
            'trend' => $this->trend,
            'trendValue' => $this->trend_value,
        ];
    }
}
