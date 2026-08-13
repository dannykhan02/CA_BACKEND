<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DocumentIntelligenceSummaryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'executiveSummary' => $this->executive_summary,
            'keyFindings' => $this->key_findings,
            'criticalRisks' => $this->critical_risks,
            'upcomingDeadlines' => $this->upcoming_deadlines,
            'importantEntities' => $this->important_entities,
            'recommendedAttention' => $this->recommended_attention,
            'promptVersion' => $this->prompt_version,
            'generatedAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
