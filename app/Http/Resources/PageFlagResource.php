<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PageFlagResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'page' => $this->page,
            'status' => $this->status,
            'note' => $this->note,
        ];
    }
}
