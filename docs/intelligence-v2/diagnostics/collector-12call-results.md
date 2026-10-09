# Collector 12-call study results

**Outcome: REJECT COLLECTOR.**

Calls attempted: 12/12. Completed: 12; failed: 0; missing: 0.
Actual spend: USD 0.396160; unknown-cost reserve: USD 0; authorized maximum: USD 1.50.
Stop reason: NONE.

## Request gate

The no-network dry run constructed all 12 requests and verified the outgoing Anthropic headers: `x-api-key` was redacted in diagnostics, `anthropic-version: 2023-06-01` and `content-type: application/json` were present, and no beta header is required by the real client path. It made zero network calls. Before every paid call, the runner compared the exact outgoing body SHA-256 with the frozen hash and checked the USD 1.50 running budget. All 12 request checks passed. Exact request hashes and provider request IDs are recorded per cell in the JSON companion.

## Cells

| Cell | State | HTTP | Stop reason | Returned | Accepted | Output tokens | Cost USD |
|---|---|---:|---|---:|---:|---:|---:|
| unicef_reduced-C1 | success | 200 | end_turn | 41 | 41 | 4811 | 0.028074 |
| unicef_reduced-K1 | success | 200 | end_turn | 40 | 40 | 5837 | 0.033207 |
| india_wash-C1 | success | 200 | end_turn | 36 | 30 | 4642 | 0.027338 |
| india_wash-K1 | success | 200 | end_turn | 46 | 45 | 5602 | 0.032141 |
| unicef_reduced-C2 | success | 200 | end_turn | 42 | 42 | 5974 | 0.033889 |
| unicef_reduced-K2 | success | 200 | end_turn | 66 | 66 | 8543 | 0.046737 |
| india_wash-C2 | success | 200 | end_turn | 28 | 26 | 3650 | 0.022378 |
| india_wash-K2 | success | 200 | end_turn | 41 | 39 | 6223 | 0.035246 |
| unicef_reduced-C3 | success | 200 | end_turn | 30 | 30 | 4960 | 0.028819 |
| unicef_reduced-K3 | success | 200 | end_turn | 78 | 78 | 10889 | 0.058467 |
| india_wash-C3 | success | 200 | end_turn | 24 | 19 | 2869 | 0.018473 |
| india_wash-K3 | success | 200 | end_turn | 36 | 29 | 5452 | 0.031391 |

## Interpretation

This file is updated from preserved artifacts. Complete three-run means are withheld for any cell with a missing or failed run. UNICEF and India are evaluated separately.

REJECT COLLECTOR. The pre-registered cost hard gate failed on both chunks: Collector mean call cost rose 52.47% on UNICEF and 44.86% on India, above the 25% maximum. All 12 calls completed with no truncation and total actual spend USD 0.396160. Mean gold recall improved, but these results cannot override a hard safety gate.

## Frozen benchmark results

Gold recall counts and sampled precision are reported separately by chunk. Precision used 20 accepted records per call, or all when fewer than 20.

| Chunk | Variant | Gold mean | Priority mean | SUPPORTED precision | SUPPORTED + PARTIAL | Negative FP mean | Rejection mean | Grounding mean | Document origin mean | Cost per call |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| UNICEF E100–E139 | CONTROL | 13.67/17 | 11.00/12 | 46.67% | 85.00% | 5.56% | 0.00% | 0.00% | 55.90% | $0.030261 |
| UNICEF E100–E139 | COLLECTOR_ONLY | 14.33/17 | 11.67/12 | 66.67% | 76.67% | 0.00% | 0.00% | 0.00% | 81.63% | $0.046137 |
| India E200–E238 | CONTROL | 8.33/12 | 7.00/10 | 88.14% | 93.22% | 0.00% | 14.88% | 4.17% | 56.48% | $0.022730 |
| India E200–E238 | COLLECTOR_ONLY | 9.67/12 | 8.00/10 | 83.33% | 93.33% | 0.00% | 8.83% | 0.00% | 43.15% | $0.032926 |

The exact per-run gold, priority, negative, validation, provenance, token, cost, stop-reason and Jaccard metrics are in the JSON companion and `paid-study/analysis/study-metrics.json`.

### Hard gates

- UNICEF: Collector gold mean +0.67 items; SUPPORTED precision +20.00 points; cost +52.47%. Cost gate **FAIL** (maximum +25%).
- India: Collector gold mean +1.33 items; SUPPORTED precision -4.80 points; cost +44.86%. Cost gate **FAIL** (maximum +25%).

No call truncated. There were no repeated new frozen negative-set matches in all three Collector runs. India `origin=document` share fell by more than 10 percentage points, a secondary safety concern. Exact downstream identity Jaccard values were low for both variants; see the JSON for each run pair.

## Decision rule

The pre-registered rule requires rejection if Collector mean cost per successful call exceeds Control by more than 25% on either chunk. It exceeded that limit on both. UNICEF mean gold recall was 13.67/17 Control versus 14.33/17 Collector; India was 8.33/12 versus 9.67/12. India sampled SUPPORTED precision fell 4.80 points, within but close to the 5-point limit. India document-origin share fell 13.33 points, exceeding the 10-point descriptive safety-concern trigger. No Collector-only truncation occurred. The recall gain was not consistent across every paired run, and UNICEF gold provenance is nonblind. Do not recommend productization from this study.

## Review provenance

The UNICEF gold set was rebuilt from source after earlier UNICEF Control outputs had been seen; it is not blind. The India source-side set preceded this experiment’s India outputs. Neither set had independent human adjudication.
The precision reviewer was an AI agent using mechanical blinding. The agent conducting the wider experiment had access to study context, so this is not equivalent to an independent human-blinded review.
Judgment SHA-256 was frozen before unblinding: `440db4fb196a9538729c5bdb591443dd45d1bde944cada55f239474ffa8c6b4e`. No human spot-check was performed.

## Key hygiene

Scanned 76 saved diagnostic artifacts against the actual API key and secret/header-value patterns. Remaining secret values: 0. Redaction occurred: no.

## Artifacts

Exact requests, responses, metadata, ledger, downstream replay and review artifacts are listed per cell in the JSON companion.
