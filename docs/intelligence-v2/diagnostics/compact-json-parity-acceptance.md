# Compact JSON parity acceptance — KEEP_CANONICAL_WIRE_FORMAT

## Contract and execution

The frozen Control prompt remained **KEEP CONTROL / FROZEN_FOR_B2**. All **12 fresh calls** ran once in the approved UNICEF A/B, India A/B, three-repeat order. There were **0 failed calls**, no retries or replacement cells, no request-hash/model/config drift, and no protocol deviation. Actual cost was **$0.289903**, below the approved **$0.60** ceiling. Per-call worst-case cost was checked against remaining actual spend before sending. [Raw requests, raw responses, response metadata, expanded canonical responses, and ledger](compact-parity-study/) are retained separately. The first compact generation call and all five subsequent compact calls accepted the provider schema; there was no provider 400.

The exact compact schema is [version `compact-json-v1`](compact-structured-output-schema-v1.json), SHA-256 `1bc072a68427391d49cccc99dbdec1f7214558db1f92fe0215159a7c248404f1`. The [prepared request manifest](compact-parity-prepared.json) was checked before every call. Frozen source/chunk/span hashes, prompt hash, canonical and compact schema hashes, model `claude-haiku-4-5-20251001`, `max_tokens=16000`, `max_records=79`, and the request-body hash all matched. Stop reasons and raw bodies are retained for both arms.

## Primary and safety metrics

The tables show the **three-run means and min–max ranges per arm**, with fixed thresholds unchanged. Rates are per accepted metric record for period and unit. Currency populated rate uses the existing typed projector's currency field over accepted metric records. Supported precision counts `SUPPORTED` judgments over the frozen blinded sample; `PARTIALLY_SUPPORTED` does not count as supported. Rejection and grounding rates use returned records. Each primary and safety metric is shown with its absolute difference and frozen threshold result. Explicit Quantity Representation measures explicit quantity representation, **not semantic completeness**.

### All six chunk-calls per arm

| Metric | Canonical mean (min–max) | Compact mean (min–max) | B−A | Absolute difference | Frozen gate |
|---|---:|---:|---:|---:|---|
| Headline Explicit Quantity Representation | 92.40% (76.92%–100.00%) | 88.64% (76.92%–100.00%) | -3.75 pp | 3.75 pp | PASS |
| Frozen gold recalled (items/call) | 11.83 (9.00–15.00) | 11.67 (8.00–15.00) | -0.17 items | 0.17 items | PASS |
| Period populated rate | 67.35% (41.67%–98.11%) | 0.00% (0.00%–0.00%) | -67.35 pp | 67.35 pp | FAIL |
| Unit populated rate | 98.11% (88.68%–100.00%) | 0.00% (0.00%–0.00%) | -98.11 pp | 98.11 pp | FAIL |
| Currency populated rate | 8.92% (0.00%–19.51%) | 4.29% (0.00%–16.00%) | -4.63 pp | 4.63 pp | PASS |
| Blinded supported precision | 55.83% (35.00%–75.00%) | 74.17% (55.00%–90.00%) | +18.33 pp | 18.33 pp | PASS |
| Rejection rate | 4.31% (0.00%–12.00%) | 5.94% (0.00%–11.11%) | +1.63 pp | 1.63 pp | PASS |
| Grounding failure rate | 0.79% (0.00%–4.76%) | 1.81% (0.00%–5.56%) | +1.02 pp | 1.02 pp | PASS |
| Parser failure rate | 0.00% (0.00%–0.00%) | 0.00% (0.00%–0.00%) | +0.00 pp | 0.00 pp | PASS |
| Structured-output failure rate | 0.00% (0.00%–0.00%) | 0.00% (0.00%–0.00%) | +0.00 pp | 0.00 pp | PASS |
| Truncation rate | 0.00% (0.00%–0.00%) | 0.00% (0.00%–0.00%) | +0.00 pp | 0.00 pp | PASS |

### UNICEF reduced, three calls per arm

| Metric | Canonical mean (min–max) | Compact mean (min–max) | B−A | Absolute difference | Frozen gate |
|---|---:|---:|---:|---:|---|
| Headline Explicit Quantity Representation | 97.62% (92.86%–100.00%) | 95.24% (92.86%–100.00%) | -2.38 pp | 2.38 pp | PASS |
| Frozen gold recalled (items/call) | 13.67 (13.00–15.00) | 14.67 (14.00–15.00) | +1.00 items | 1.00 items | PASS |
| Period populated rate | 79.60% (48.78%–98.11%) | 0.00% (0.00%–0.00%) | -79.60 pp | 79.60 pp | FAIL |
| Unit populated rate | 96.23% (88.68%–100.00%) | 0.00% (0.00%–0.00%) | -96.23 pp | 96.23 pp | FAIL |
| Currency populated rate | 17.84% (15.09%–19.51%) | 8.59% (0.00%–16.00%) | -9.26 pp | 9.26 pp | FAIL |
| Blinded supported precision | 45.00% (35.00%–65.00%) | 66.67% (55.00%–80.00%) | +21.67 pp | 21.67 pp | PASS |
| Rejection rate | 1.59% (0.00%–4.76%) | 2.58% (0.00%–5.56%) | +0.99 pp | 0.99 pp | PASS |
| Grounding failure rate | 1.59% (0.00%–4.76%) | 2.58% (0.00%–5.56%) | +0.99 pp | 0.99 pp | PASS |
| Parser failure rate | 0.00% (0.00%–0.00%) | 0.00% (0.00%–0.00%) | +0.00 pp | 0.00 pp | PASS |
| Structured-output failure rate | 0.00% (0.00%–0.00%) | 0.00% (0.00%–0.00%) | +0.00 pp | 0.00 pp | PASS |
| Truncation rate | 0.00% (0.00%–0.00%) | 0.00% (0.00%–0.00%) | +0.00 pp | 0.00 pp | PASS |

### India WASH, three calls per arm

| Metric | Canonical mean (min–max) | Compact mean (min–max) | B−A | Absolute difference | Frozen gate |
|---|---:|---:|---:|---:|---|
| Headline Explicit Quantity Representation | 87.18% (76.92%–100.00%) | 82.05% (76.92%–84.62%) | -5.13 pp | 5.13 pp | FAIL |
| Frozen gold recalled (items/call) | 10.00 (9.00–12.00) | 8.67 (8.00–10.00) | -1.33 items | 1.33 items | FAIL |
| Period populated rate | 55.10% (41.67%–63.64%) | 0.00% (0.00%–0.00%) | -55.10 pp | 55.10 pp | FAIL |
| Unit populated rate | 100.00% (100.00%–100.00%) | 0.00% (0.00%–0.00%) | -100.00 pp | 100.00 pp | FAIL |
| Currency populated rate | 0.00% (0.00%–0.00%) | 0.00% (0.00%–0.00%) | +0.00 pp | 0.00 pp | PASS |
| Blinded supported precision | 66.67% (55.00%–75.00%) | 81.67% (75.00%–90.00%) | +15.00 pp | 15.00 pp | PASS |
| Rejection rate | 7.03% (3.03%–12.00%) | 9.30% (7.41%–11.11%) | +2.27 pp | 2.27 pp | PASS |
| Grounding failure rate | 0.00% (0.00%–0.00%) | 1.04% (0.00%–3.12%) | +1.04 pp | 1.04 pp | PASS |
| Parser failure rate | 0.00% (0.00%–0.00%) | 0.00% (0.00%–0.00%) | +0.00 pp | 0.00 pp | PASS |
| Structured-output failure rate | 0.00% (0.00%–0.00%) | 0.00% (0.00%–0.00%) | +0.00 pp | 0.00 pp | PASS |
| Truncation rate | 0.00% (0.00%–0.00%) | 0.00% (0.00%–0.00%) | +0.00 pp | 0.00 pp | PASS |

The decisive failures are period and unit in **both** chunks. Every compact run had 0% populated period and 0% populated unit among accepted metrics. Canonical period minima were 48.78% for UNICEF and 41.67% for India; canonical unit minima were 88.68% and 100%. Those compact results are clearly outside canonical run-to-run ranges and exceed the frozen 5 pp deterioration threshold. UNICEF currency also fell 9.26 pp, outside its canonical range. India headline representation fell 5.13 pp, crossing its threshold but within the canonical arm's own range; India gold recall fell 1.33 items per chunk-call average, below the canonical minimum of 9. No frozen threshold was relaxed for variance.

There were no parser, expansion, structured-output, or truncation failures. Every stop reason was `end_turn`. Under the pre-registered rule, no compact-attributable truncation occurred.

## Volume and cost

| Measure | Canonical | Compact |
|---|---:|---:|
| Returned records | 230 | 225 |
| Accepted records | 222 | 214 |
| Input tokens | 24441 | 26637 |
| Output tokens | 29940 | 17825 |
| Output tokens per returned record | 130.17 | 79.22 |
| Provider cost | $0.174141 | $0.115762 |
| Cost per accepted record | $0.000784 | $0.000541 |

Observed output-token reduction per comparable returned record: **39.14%**. It clears the 20% default savings requirement, but correctness gates fail. The offline 25% gate was previously satisfied for the actual representation; observed output savings do not compensate for lost metadata.

Cost uses the configured Haiku 4.5 rates, verified against [Anthropic's model pricing](https://platform.claude.com/docs/en/models/haiku-4-5/overview): $1 per million input tokens, $5 per million output tokens, $1.25 per million 5-minute cache-write tokens, and $0.10 per million cache-read tokens.

## Blinded precision review and unblinding

An **independent Codex AI coding/review agent**, with no arm or run labels, judged 240 shuffled accepted records and their cited source spans. No independent human reviewer was available. The agent that ran provider calls did not perform an unblinded precision review. The packet hash was `4f7725d3a6188b6dfae14bcc3f93b5db8633df6a73eb4669271512d3b39b5f43`. Judgment hash `8963639954155455ad8a21546babaa920e44d038180f04e8ff54989390367cc8` was [frozen before unblinding](compact-parity-study/precision-review/judgment-freeze.json). The [private unblinding mapping](compact-parity-study/precision-review/precision-review-private-mapping.json) has SHA-256 `9a2313c7549f9e77ef6ed8dc17e24843d6c3ec68856088761f87a73b42007aa9`. [Judgments](compact-parity-study/precision-review/precision-review-judgments.json) and [unblinded results](compact-parity-study/precision-review/precision-review-unblinded.json) are retained. Canonical supported precision was 67/120 (55.83%); compact was 89/120 (74.17%). These are engineering samples, not statistical proof. The reviewer flagged chart attribution and clipped-span context concerns without knowing arm labels.

## Qualitative semantic check

[Twenty compact records](compact-parity-study/qualitative-sample.json), ten per chunk, were examined as raw compact record → expanded canonical record → cited source span. The [review notes](compact-parity-study/qualitative-review.json) record each item. Expansion changed no literal label, value, or subject and lost no evidence ID. All 20 raw records omitted `p` and `u`, which the expander correctly filled as `null`. Several cited spans state a period or unit explicitly: for example, E101 states 2024 and USD millions, E120 states 2024 and people, E218 states the last five years and households, and E237 states 2024 and people. One person entity omitted explicit `entity_type`. A flattened E103 chart produced uncertain year/series attribution; an E236 item added a qualifier beyond its cited span. This is a provider-output semantic/metadata regression under the compact schema, not an expander mutation.

## Artifact audit and decision

The [secret scan](compact-parity-study/artifact-secret-scan.json) examined generated study artifacts for Anthropic keys, authorization headers, bearer tokens, and configured secret environment values; result: **PASS, no findings**. No artifact was published externally. Full machine-readable metrics and ranges are in the [parity JSON report](compact-parity-study/compact-parity-report.json). There was no abort or protocol deviation.

**KEEP_CANONICAL_WIRE_FORMAT**. Compact's metadata omissions cross frozen FAIL thresholds and fall clearly outside canonical run-to-run ranges. The production default remains `canonical-json-span-v1`; it was not flipped. No push, merge, or deployment occurred. This is an engineering acceptance test on two frozen chunks, not statistical proof or new extraction-prompt research.
