# Final pre-B2 paid prompt study

**Decision: KEEP CONTROL. Extraction status: FROZEN_FOR_B2. Interpretation: DIRECTIONAL.**

The two frozen chunks and five fresh paired runs per chunk produced no preregistered meaningful gain from `CONTROL_MINUS_MATERIALITY`. In the UNICEF chunk, B also breached the supported-precision and rejection-rate safety gates. The experiment is complete; no further extraction experiment is required before B2.

## Protocol and request integrity

The runner used the frozen [experiment plan](pre-b2/minimal-prompt-experiment-plan.json) and [version contract](pre-b2/version-freeze-plan.json). It checked the frozen model, prompt, system hash, schema, canonical wire format, source, span set, planner, and complete request-body hash before each send. B removed only the two frozen materiality-selection sentences. The calls interleaved UNICEF A/B and India A/B for runs 1–5. No historical Control call was reused, and no retry or replacement occurred. All 20 provider calls succeeded; parser failures, malformed structured outputs, and max-token truncations were zero. Exact requests, responses, metadata, the [request manifest](pre-b2/final-study/request-hash-manifest.json), and [ledger](pre-b2/final-study/ledger.json) are preserved. The starting repo HEAD was `618e713b68852f62ad45b29318a5f947f4cc6cfe`; the working tree was clean before study artifacts were created.

The canonical JSON format remained active. Production prompt and extraction behavior were unchanged. No Candidate Inventory DB write, push, merge, or deploy occurred.

## Primary outcomes

The validated Candidate Inventory V1 and CandidateRepresentation matcher were replayed against **accepted typed evidence** after existing validation. OCR/chart ambiguous quantities were excluded from headline denominators. The metric below is **EXPLICIT QUANTITY REPRESENTATION**; it does not measure semantic completeness.

| Frozen chunk | Control headline mean | B headline mean | Control gold mean | B gold mean | Positive paired runs, representation / gold |
| --- | ---: | ---: | ---: | ---: | ---: |
| UNICEF, 14 eligible candidates and 17 gold items | 94.29% | 92.86% | 14.8 / 17 | 14.8 / 17 | 1 / 5 · 1 / 5 |
| India WASH, 13 eligible candidates and 12 gold items | 81.54% | 89.23% | 8.4 / 12 | 9.6 / 12 | 2 / 5 · 3 / 5 |
| Balanced mean | **87.91%** | **91.04%** | **11.6 items** | **12.2 items** | Majority condition fails in UNICEF |

B gained **3.13 percentage points** in balanced headline representation, below the frozen **5-point** minimum. It gained **0.6 gold item per chunk-call**, below the **1-item** minimum. For headline candidate instances across all ten calls per arm, Control represented 119 / 135 and B represented 123 / 135. By class, Control versus B was: percentage **29 / 30 vs 30 / 30**; currency **33 / 35 vs 32 / 35**; scaled quantity **57 / 70 vs 61 / 70**. Individual candidate statuses, including `REPRESENTED`, `UNREPRESENTED`, `INDETERMINATE`, and `MENTIONED_IN_TEXT_ONLY`, are in the [candidate results](pre-b2/final-study/candidate-representation-results.json). The [gold comparison](pre-b2/final-study/gold-recall-comparison.json) uses the frozen item set without relabeling it after outputs were seen.

## Safety review

The existing blind-review protocol mechanically hid arm and cell identity for **396 sampled accepted records** (up to 20 per cell). An **AI coding agent** adjudicated the frozen blind packet against cited source spans; this was not an independent human review. Judgments were frozen at SHA-256 `f50be77a8feba83e70bbf83a057d5072bab27fa11ec545c8e392c15e85477628` before unblinding. The [packet, judgments, mapping, and unblinded review](pre-b2/final-study/precision-review/) are preserved. Frozen negative items were reviewed against accepted evidence; no negative-set false positive was found.

| Frozen chunk | Control supported precision | B supported precision | Mean Control rejection rate | Mean B rejection rate | Safety result |
| --- | ---: | ---: | ---: | ---: | --- |
| UNICEF | 68 / 100 = 68% | 60 / 100 = 60% | 0% | 8.47% | **Fails:** precision −8 pp; rejection +8.47 pp |
| India WASH | 93 / 98 = 94.90% | 94 / 98 = 95.92% | 12.21% | 7.54% | Passes measured gates |

The preregistered limits were at most **5 pp** deterioration in precision, negative false positives, or rejection rate, and **2 pp** in grounding failure. UNICEF B5 returned 71 records, but 29 were rejected for `due_date_wrong_format`; the failed records remain in saved artifacts. This outlier materially contributes to UNICEF's B rejection rate. Pooled precision was 161 / 198 = 81.31% for Control and 154 / 198 = 77.78% for B; the chunk-specific UNICEF breach is decisive. Negative-set false positives were 0 for both arms; B had no grounding rejection, truncation, parser failure, or structured-output failure. Full per-cell values and rejection reasons are in [precision and safety metrics](pre-b2/final-study/precision-safety-metrics.json).

## Volume, cost, and downstream observations

| Measure, mean per call unless stated | Control | B |
| --- | ---: | ---: |
| Returned records | 35.4 | 47.8 |
| Accepted records | 33.8 | 43.8 |
| Output tokens | 4,706.1 | 6,292.4 |
| Input tokens | 4,073.5 | 4,014.5 |
| Arm cost | $0.276040 | $0.354765 |

Actual total cost was **$0.630805** against the approved **$1.50** cap. The largest paired chunk cost was **$0.090504** against **$0.15**. B's incremental cost was **$0.078725**, or **28.52%** relative to Control; this relative increase was descriptive, not a rejection gate. Net additional represented candidate instances were 4, costing **$0.01968 each**; net additional gold item instances were 6, costing **$0.01312 each**. Both were below the approved $0.10 and $0.15 cost-per-gain caps. No call exceeded `max_records` (maximum returned/max_records **0.899**); maximum output_tokens/max_tokens was **0.616**. [Per-call results](pre-b2/final-study/per-call-results.json), [paired comparisons](pre-b2/final-study/paired-run-comparison.json), and [cost analysis](pre-b2/final-study/cost-analysis.json) retain these ratios and counts.

B's higher record volume was expected under the frozen protocol and was not itself treated as failure. Candidate-set representation-insensitive Jaccard averaged about **0.87** for UNICEF and **0.89** for India paired runs. The [provenance and key-figure comparison](pre-b2/final-study/provenance-key-figure-comparison.json) records per-cell distributions, typed counts, and eligibility. No materiality tiers were replayed from unavailable context.

## Decision and extraction freeze

The frozen minimum useful effect failed, the required majority of positive paired runs did not occur in UNICEF, and UNICEF B crossed two safety limits. Absolute budget gates passed. Therefore the exact decision is **KEEP CONTROL**. This is a **DIRECTIONAL** conclusion from two existing chunks; it does not establish behavior across document types.

The [decision record](pre-b2/final-study/final-decision.json) and [extraction freeze](pre-b2/final-study/final-extraction-freeze.json) mark Control `pre-b2-control-v1` with Haiku `claude-haiku-4-5-20251001`, system prompt hash `026063bffca0cfc4c82dd9f4eddd7330cf1be26fc316e62cbac387ec54f56985`, schema `evidence-schema-span-v1-snapshot` hash `69dcb0125c131c024670b325496d8abcc868a2a23cebb9df8c21b7071380c873`, canonical wire format `canonical-json-span-v1`, detector `candidate-inventory-v1.0.0`, matcher `candidate-representation-v1.0.0`, planner/config hash `e55066f545a0b42a4570f0d48dfe447a6a56984602f69b63a65d53708c3051d2`, and frozen request hashes as **FROZEN_FOR_B2**.

`COMPACT_CODEC_WORTH_IMPLEMENTING=true`; offline estimated savings were about **29.5%**. Production activation remains deferred. Other deferred work is recorded in the freeze artifact. Extraction is frozen for B2 despite known imperfections; none of the study findings justifies delaying B2.
