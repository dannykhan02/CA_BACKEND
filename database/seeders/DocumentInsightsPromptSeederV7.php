<?php

namespace Database\Seeders;

use App\Models\AiPrompt;
use Illuminate\Database\Seeder;

class DocumentInsightsPromptSeederV7 extends Seeder
{
    public function run(): void
    {
        if (AiPrompt::where('name', 'document_insights')->where('version', 7)->exists()) {
            return;
        }
        if (! AiPrompt::where('name', 'document_insights')->where('version', 6)->exists()) {
            $this->call(DocumentInsightsPromptSeederV6::class);
        }
        $base = AiPrompt::where('name', 'document_insights')->where('version', 6)->sole();
        $rule = <<<'PROMPT'
15. KPI identity metadata: preserve label exactly as the extracted/source-facing wording. In the same response, add an identity object per KPI, using null for information the document does not establish. concept describes the measured quantity without reporting-period text; scope names the population, organization, product or internal/external boundary. Never omit a material qualifier. metric_type is amount, count, percentage, rate, duration, ratio or index (use percentage for %). quantity_kind is absolute or change; aggregation is total or average when established; value_basis is actual or target. period preserves the observation's quarter/year/date wording and is not the measurement concept. Keep different scopes, currencies, counts vs percentages, fee vs fee increase, actual vs target and total vs average distinct. Pending tickets and pending tickets outside timelines are different populations. In "tickets closed outside target timelines", target describes the deadline criterion, not a target value: value_basis is actual. Use concept "service charter performance" with scope internal or external for charter performance; use concept "service charter tickets closed outside timelines" for that specific count. Do not invent missing identity information or conflate related quantities.

PROMPT;
        $template = str_replace('Respond with ONLY valid JSON matching this exact schema', $rule.'Respond with ONLY valid JSON matching this exact schema', $base->template);
        $template = str_replace('"trendValue": string|null}', '"trendValue": string|null, "identity": {"concept": string|null, "scope": string|null, "metric_type": string|null, "quantity_kind": "absolute"|"change"|null, "aggregation": "total"|"average"|null, "value_basis": "actual"|"target"|null, "period": string|null}}', $template);
        AiPrompt::create([
            'name' => 'document_insights', 'version' => 7, 'provider' => $base->provider,
            'model' => $base->model, 'temperature' => $base->temperature, 'system_prompt' => $base->system_prompt,
            'template' => $template, 'active' => false,
        ]);
    }
}
