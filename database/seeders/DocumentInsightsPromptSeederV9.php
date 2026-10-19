<?php

namespace Database\Seeders;

use App\Models\AiPrompt;
use Illuminate\Database\Seeder;

/** Inactive successor clarifying source-facing identity and composite table evidence. */
class DocumentInsightsPromptSeederV9 extends Seeder
{
    public function run(): void
    {
        if (AiPrompt::where('name', 'document_insights')->where('version', 9)->exists()) {
            return;
        }
        if (! AiPrompt::where('name', 'document_insights')->where('version', 8)->exists()) {
            $this->call(DocumentInsightsPromptSeederV8::class);
        }
        $base = AiPrompt::where('name', 'document_insights')->where('version', 8)->sole();
        $template = str_replace('value_basis is actual or target.',
            'value_basis is actual, target, budget, forecast, proposed, approved or incurred only when the source supports it.',
            $base->template);
        $rule = <<<'PROMPT'
17. A KPI identity's scope is only its source-supported entity or population, stated as briefly as possible. Never put explanations of the metric, calculations, exclusions, confidence, or management interpretation in scope. Keep those claims out of the KPI unless independently supported. A source citation must support the entire scope; when it cannot, use null rather than an expanded guess. For a PDF table, cite the actual value row and its column heading; if the column says only Q3 but a governing page heading says Q3 2026, return the full period only when both identify the same quarter and year. A proposal table can have a monetary unit but no investment reporting period: leave period null unless a schedule is explicitly stated. For a wrapped column heading, cite its first span; the following adjacent continuation may complete its unit. Do not classify proposed funding as a target, budget approval, or incurred cost.

PROMPT;
        $template = str_replace('Respond with ONLY valid JSON matching this exact schema',
            $rule.'Respond with ONLY valid JSON matching this exact schema', $template);
        AiPrompt::create(['name' => 'document_insights', 'version' => 9,
            'provider' => $base->provider, 'model' => $base->model,
            'temperature' => $base->temperature, 'system_prompt' => $base->system_prompt,
            'template' => $template, 'active' => false]);
    }
}
