"""Render the frozen compact parity result; no provider calls."""
import json
from pathlib import Path

HERE = Path(__file__).resolve().parent
OUT = HERE / 'compact-parity-study'
x = json.loads((OUT / 'compact-parity-report.json').read_text())
labels = {
 'headline_explicit_quantity_representation':'Headline Explicit Quantity Representation',
 'gold_recalled':'Frozen gold recalled (items/call)',
 'period_populated_rate':'Period populated rate',
 'unit_populated_rate':'Unit populated rate',
 'currency_populated_rate':'Currency populated rate',
 'supported_precision':'Blinded supported precision',
 'rejection_rate':'Rejection rate',
 'grounding_failure_rate':'Grounding failure rate',
 'parser_failure':'Parser failure rate',
 'structured_output_failure':'Structured-output failure rate',
 'truncated':'Truncation rate',
}

def fmt(key,value):
    return f'{value:.2f}' if key == 'gold_recalled' else f'{100*value:.2f}%'

def table(metrics):
    lines=['| Metric | Canonical mean (min–max) | Compact mean (min–max) | B−A | Absolute difference | Frozen gate |',
           '|---|---:|---:|---:|---:|---|']
    for key,label in labels.items():
        v=metrics[key];a=v['canonical'];b=v['compact'];d=v['absolute_difference_compact_minus_canonical']
        delta=f'{d:+.2f} items' if key=='gold_recalled' else f'{100*d:+.2f} pp'
        absolute=f'{abs(d):.2f} items' if key=='gold_recalled' else f'{100*abs(d):.2f} pp'
        lines.append(f"| {label} | {fmt(key,a['mean'])} ({fmt(key,a['minimum'])}–{fmt(key,a['maximum'])}) | "
                     f"{fmt(key,b['mean'])} ({fmt(key,b['minimum'])}–{fmt(key,b['maximum'])}) | "
                     f"{delta} | {absolute} | {'PASS' if v['passes_frozen_threshold'] else 'FAIL'} |")
    return '\n'.join(lines)
a=x['arm_totals']['A'];b=x['arm_totals']['B']
text=f'''# Compact JSON parity acceptance — {x['decision']}

## Contract and execution

The frozen Control prompt remained **KEEP CONTROL / FROZEN_FOR_B2**. All **12 fresh calls** ran once in the approved UNICEF A/B, India A/B, three-repeat order. There were **0 failed calls**, no retries or replacement cells, no request-hash/model/config drift, and no protocol deviation. Actual cost was **${x['actual_total_cost_usd']}**, below the approved **$0.60** ceiling. Per-call worst-case cost was checked against remaining actual spend before sending. [Raw requests, raw responses, response metadata, expanded canonical responses, and ledger](compact-parity-study/) are retained separately. The first compact generation call and all five subsequent compact calls accepted the provider schema; there was no provider 400.

The exact compact schema is [version `compact-json-v1`](compact-structured-output-schema-v1.json), SHA-256 `1bc072a68427391d49cccc99dbdec1f7214558db1f92fe0215159a7c248404f1`. The [prepared request manifest](compact-parity-prepared.json) was checked before every call. Frozen source/chunk/span hashes, prompt hash, canonical and compact schema hashes, model `claude-haiku-4-5-20251001`, `max_tokens=16000`, `max_records=79`, and the request-body hash all matched. Stop reasons and raw bodies are retained for both arms.

## Primary and safety metrics

The tables show the **three-run means and min–max ranges per arm**, with fixed thresholds unchanged. Rates are per accepted metric record for period and unit. Currency populated rate uses the existing typed projector's currency field over accepted metric records. Supported precision counts `SUPPORTED` judgments over the frozen blinded sample; `PARTIALLY_SUPPORTED` does not count as supported. Rejection and grounding rates use returned records. Each primary and safety metric is shown with its absolute difference and frozen threshold result. Explicit Quantity Representation measures explicit quantity representation, **not semantic completeness**.

### All six chunk-calls per arm

{table(x['overall'])}

### UNICEF reduced, three calls per arm

{table(x['by_chunk']['unicef_reduced'])}

### India WASH, three calls per arm

{table(x['by_chunk']['india_wash'])}

The decisive failures are period and unit in **both** chunks. Every compact run had 0% populated period and 0% populated unit among accepted metrics. Canonical period minima were 48.78% for UNICEF and 41.67% for India; canonical unit minima were 88.68% and 100%. Those compact results are clearly outside canonical run-to-run ranges and exceed the frozen 5 pp deterioration threshold. UNICEF currency also fell 9.26 pp, outside its canonical range. India headline representation fell 5.13 pp, crossing its threshold but within the canonical arm's own range; India gold recall fell 1.33 items per chunk-call average, below the canonical minimum of 9. No frozen threshold was relaxed for variance.

There were no parser, expansion, structured-output, or truncation failures. Every stop reason was `end_turn`. Under the pre-registered rule, no compact-attributable truncation occurred.

## Volume and cost

| Measure | Canonical | Compact |
|---|---:|---:|
| Returned records | {a['returned_records']} | {b['returned_records']} |
| Accepted records | {a['accepted_records']} | {b['accepted_records']} |
| Input tokens | {a['input_tokens']} | {b['input_tokens']} |
| Output tokens | {a['output_tokens']} | {b['output_tokens']} |
| Output tokens per returned record | {a['output_tokens_per_returned_record']:.2f} | {b['output_tokens_per_returned_record']:.2f} |
| Provider cost | ${a['total_cost_usd']} | ${b['total_cost_usd']} |
| Cost per accepted record | ${float(a['cost_per_accepted_record_usd']):.6f} | ${float(b['cost_per_accepted_record_usd']):.6f} |

Observed output-token reduction per comparable returned record: **{100*x['observed_output_token_reduction_per_returned_record']:.2f}%**. It clears the 20% default savings requirement, but correctness gates fail. The offline 25% gate was previously satisfied for the actual representation; observed output savings do not compensate for lost metadata.

Cost uses the configured Haiku 4.5 rates, verified against [Anthropic's model pricing](https://platform.claude.com/docs/en/models/haiku-4-5/overview): $1 per million input tokens, $5 per million output tokens, $1.25 per million 5-minute cache-write tokens, and $0.10 per million cache-read tokens.

## Blinded precision review and unblinding

An **independent Codex AI coding/review agent**, with no arm or run labels, judged 240 shuffled accepted records and their cited source spans. No independent human reviewer was available. The agent that ran provider calls did not perform an unblinded precision review. The packet hash was `{x['precision_review']['packet_sha256']}`. Judgment hash `{x['precision_review']['judgment_sha256']}` was [frozen before unblinding](compact-parity-study/precision-review/judgment-freeze.json). The [private unblinding mapping](compact-parity-study/precision-review/precision-review-private-mapping.json) has SHA-256 `{x['precision_review']['unblinding_mapping_sha256']}`. [Judgments](compact-parity-study/precision-review/precision-review-judgments.json) and [unblinded results](compact-parity-study/precision-review/precision-review-unblinded.json) are retained. Canonical supported precision was 67/120 (55.83%); compact was 89/120 (74.17%). These are engineering samples, not statistical proof. The reviewer flagged chart attribution and clipped-span context concerns without knowing arm labels.

## Qualitative semantic check

[Twenty compact records](compact-parity-study/qualitative-sample.json), ten per chunk, were examined as raw compact record → expanded canonical record → cited source span. The [review notes](compact-parity-study/qualitative-review.json) record each item. Expansion changed no literal label, value, or subject and lost no evidence ID. All 20 raw records omitted `p` and `u`, which the expander correctly filled as `null`. Several cited spans state a period or unit explicitly: for example, E101 states 2024 and USD millions, E120 states 2024 and people, E218 states the last five years and households, and E237 states 2024 and people. One person entity omitted explicit `entity_type`. A flattened E103 chart produced uncertain year/series attribution; an E236 item added a qualifier beyond its cited span. This is a provider-output semantic/metadata regression under the compact schema, not an expander mutation.

## Artifact audit and decision

The [secret scan](compact-parity-study/artifact-secret-scan.json) examined generated study artifacts for Anthropic keys, authorization headers, bearer tokens, and configured secret environment values; result: **PASS, no findings**. No artifact was published externally. Full machine-readable metrics and ranges are in the [parity JSON report](compact-parity-study/compact-parity-report.json). There was no abort or protocol deviation.

**{x['decision']}**. Compact's metadata omissions cross frozen FAIL thresholds and fall clearly outside canonical run-to-run ranges. The production default remains `canonical-json-span-v1`; it was not flipped. No push, merge, or deployment occurred. This is an engineering acceptance test on two frozen chunks, not statistical proof or new extraction-prompt research.
'''
(HERE / 'compact-json-parity-acceptance.md').write_text(text)
print('wrote report', x['decision'])
