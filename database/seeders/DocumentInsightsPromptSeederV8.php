<?php

namespace Database\Seeders;

use App\Models\AiPrompt;
use Illuminate\Database\Seeder;

/** Opt-in normal-route KPI field citations. Activation remains an operational choice. */
class DocumentInsightsPromptSeederV8 extends Seeder
{
    public function run(): void
    {
        if (AiPrompt::where('name', 'document_insights')->where('version', 8)->exists()) {
            return;
        }
        if (! AiPrompt::where('name', 'document_insights')->where('version', 7)->exists()) {
            $this->call(DocumentInsightsPromptSeederV7::class);
        }
        $base = AiPrompt::where('name', 'document_insights')->where('version', 7)->sole();
        $rule = <<<'PROMPT'
16. KPI citations: when the document text has [E...] span identifiers, return source_context for each KPI. Give the exact span identifier containing the reported value, and the identifiers containing its unit, period, and subject when those are separate. Use null for a missing field; never invent a citation or infer a unit from location, neighboring magnitude, or numeric formatting. The value span must identify the measured concept or subject as well as the value. A heading or legend can supply a unit or period only when it clearly governs that value's table row. Keep value as the source's original representation. Distinguish actual, target, budget, forecast, proposed, approved, and incurred; a proposal is not an expense. Do not compute a change or trend from one observation.

PROMPT;
        $template = str_replace('Respond with ONLY valid JSON matching this exact schema', $rule.'Respond with ONLY valid JSON matching this exact schema', $base->template);
        $template = str_replace('"trendValue": string|null, "identity":', '"trendValue": string|null, "source_context": {"value_span_id": string|null, "unit_span_id": string|null, "period_span_id": string|null, "subject_span_id": string|null}, "identity":', $template);
        $template = str_replace('"value_basis": "actual"|"target"|null', '"value_basis": "actual"|"target"|"budget"|"forecast"|"proposed"|"approved"|"incurred"|null', $template);
        AiPrompt::create([
            'name' => 'document_insights', 'version' => 8, 'provider' => $base->provider,
            'model' => $base->model, 'temperature' => $base->temperature,
            'system_prompt' => $base->system_prompt, 'template' => $template, 'active' => false,
        ]);
    }
}
