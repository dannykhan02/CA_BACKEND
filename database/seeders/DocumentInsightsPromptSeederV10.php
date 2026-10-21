<?php

namespace Database\Seeders;

use App\Models\AiPrompt;
use Illuminate\Database\Seeder;
use RuntimeException;

/** Inactive observation contract; V9 remains available for existing documents. */
class DocumentInsightsPromptSeederV10 extends Seeder
{
    public function run(): void
    {
        if (AiPrompt::where('name', 'document_insights')->where('version', 10)->exists()) {
            return;
        }
        if (! AiPrompt::where('name', 'document_insights')->where('version', 9)->exists()) {
            $this->call(DocumentInsightsPromptSeederV9::class);
        }
        $base = AiPrompt::where('name', 'document_insights')->where('version', 9)->sole();
        $template = $base->template;
        $replacements = [
            'every KPI, chart value, trend, and insight' => 'every KPI observation and insight',
            '8. Charts: only create a chart when visualization is genuinely useful — bar for category comparisons, line for values changing over an ordered sequence, pie only for meaningful parts of a whole. Do not chart unrelated numbers together, and do not create a chart from ambiguous or insufficient data.'
                => '8. Analytical operands: emit separate, cited KPI observations for the periods and comparison operands needed to support a meaningful trend or target comparison. Do not emit chart JSON; the application constructs trusted charts from verified observations.',
            '13. Targets vs. measured values: a mandated target, threshold, or goal (e.g. "against a mandated target of 90%") is NOT a measured data point and must NEVER appear as an entry in charts[].data, regardless of chart type. If the document states both a time series (e.g. quarterly compliance rates) and a separate target/threshold, chart only the actual measured values; mention the target in the chart\'s description or in insights instead. Do not let a target value be interpolated into a trend line as if it were a future or additional period.'
                => '13. Targets vs. measured values: emit an actual and a target as distinct cited observations with the same supported period and concept. Preserve a printed ceiling or floor operator in value (for example ≤ 90), and never classify a target as an actual value.',
            '14. Description/data consistency: every metric named in a chart\'s description must have a corresponding value actually present in that chart\'s data array. If you cannot populate every metric your description mentions, either omit the unavailable metric from the description or omit the chart entirely — never publish a description that promises data you did not include.'
                => '14. Observation consistency: each KPI observation needs its own value, unit, period, scope, basis and field-role citations. If any essential field is unsupported, use null and do not imply it was verified.',
            "\"charts\": [{\"type\": \"bar\"|\"line\"|\"pie\", \"title\": string, \"description\": string, \"data\": [{\"label\": string, \"value\": number}]}],\n"
                => '',
        ];
        foreach ($replacements as $from => $to) {
            if (! str_contains($template, $from)) {
                throw new RuntimeException('V10 prompt base changed: expected V9 clause missing.');
            }
            $template = str_replace($from, $to, $template);
        }
        $template = preg_replace('/^7\. Trends:.*$/mu',
            '7. Comparisons: emit the independently cited operands when the document supports a meaningful comparison. Set trend and trendValue to null; the application calculates direction from verified values.',
            $template, -1, $trendRules);
        if ($trendRules !== 1) {
            throw new RuntimeException('V10 prompt base changed: trend clause missing.');
        }
        $rule = <<<'PROMPT'
18. Independent observations: kpis is a list of source-facing quantitative observations, not one KPI per metric. For a meaningful multi-period trend, emit one observation per relevant period with its own value and four source_context span IDs. For actual-versus-target or budget comparisons, emit each operand separately. Do not emit every incidental number: prioritize meaningful measurements and comparisons. Keep the original unit and scale; never convert a written value. Keep a printed comparison operator such as ≤ or ≥ in value. Set trend and trendValue to null; the application computes comparisons.

19. Citation integrity: cite the exact value row, the governing unit and period headers, and the genuine governing entity or population header when scope is absent from the numerical row. A row's proximity to a company name is not proof that the company owns the value. Never reuse a value-row citation as proof of a scope it does not print. For a periodless fact, leave identity.period and period_span_id null. Distinguish actual, target, budget, forecast, proposed, approved and incurred; hypothetical or proposed values must not become actuals. If two source observations conflict without an explicit resolution, preserve the conflict in insights and omit a definitive KPI. Do not repeat an identical observation.

PROMPT;
        $needle = 'Respond with ONLY valid JSON matching this exact schema';
        if (! str_contains($template, $needle)) {
            throw new RuntimeException('V10 prompt base changed: schema marker missing.');
        }
        $template = str_replace($needle, $rule.$needle, $template);
        $template = str_replace('Empty kpis/charts arrays', 'An empty kpis array', $template);
        $template = str_replace('KPIs or chart data', 'KPI observations', $template);
        $template = str_replace('KPI or chart data', 'KPI observations', $template);
        $template = str_replace('"trend" always reflects the numeric value\'s own direction (see rule 7) — never whether that direction is a good or bad outcome for the metric.',
            'trend and trendValue must be null; verified comparisons are calculated by the application.', $template);
        $template = str_replace('every trend reflects the numeric value\'s own direction, not the favorability of the outcome',
            'each comparison operand is independently cited', $template);
        $template = str_replace('no target/threshold value was included as a chart data point',
            'targets and actuals were emitted as separate observations', $template);
        $template = str_replace('KPI, chart data, or narrative finding', 'KPI or narrative finding', $template);
        $template = str_replace('KPIs/charts', 'KPIs', $template);
        AiPrompt::create(['name' => 'document_insights', 'version' => 10,
            'provider' => $base->provider, 'model' => $base->model,
            'temperature' => $base->temperature, 'system_prompt' => $base->system_prompt,
            'template' => $template, 'active' => false]);
    }
}
