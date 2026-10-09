# UNICEF safe attribution fixture — READY

**Diagnostic fixture only.** This subchunk is deliberately cut from current root chunk 0 for a controlled four-call root-cause experiment. It is **not** the production planner's normal output. This preparation made zero Anthropic network calls and changed no production behavior.

## Verified inputs and selection

- Full source: 58,536 bytes, SHA-256 `c919b4bb36fb3467906cc176a72d57c422260d6a17bbb4f4affc05c93415015c`.
- Authentic stored source-span export: 360 rows, SHA-256 `df213788ebdc82a9fa98280cc47eed71c2f90862e027fe02b42fbd0882021b55`; extraction version `8acd7d011fac21b31b65c5dfa251423d`.
- Current fallback-planned root chunk 0: E001–E186, pages 1–12, character offsets `[1, 29519)`, estimated record pressure 67.
- The scan evaluated **all 17,391 contiguous, stored-span-aligned sections** within root 0 using the current `ProactiveChunkRisk::assess()` estimator and the planner's per-span `ceil((source span bytes + label overhead)/3)` input weight. **1,581** candidates had estimated pressure 50–60 inclusive. The chosen section is the **largest by source character length**. No stored span was divided, regenerated, renumbered, or remapped.

| Property | Selected diagnostic subchunk |
|---|---|
| Stored span range | **E033–E186**, 154 spans |
| Page range | **3–12** from stored span rows |
| Character offsets | **[4,218, 29,519)** in the full extracted text |
| Length | **25,301 characters; 25,463 UTF-8 bytes** |
| Exact source SHA-256 | `803c45e8e6a1e38952f213d70b95aa9ab62aa02dc7bbf206df156d6d59cf18fc` |
| Canonical production span-map SHA-256 | `76fae4cb072a555ba93770a550c62c7c616c12d084fcd956255393ceac1957d5` |
| Rendered labeled payload SHA-256 | `42038a1445fa9b766b1dccd131c35be8296d53524dba9247dcd3705e9c9d31bf` |
| Serialized Control request SHA-256 | `143b3385379edaa0955625350eb69da3a59814ad23dffb0676bbf073ccfccd91` |

The span-map hash above is SHA-256 of the compact `EvidenceSpanSet::all()` JSON used by the probe. For byte-level artifact identification, the pretty-printed normalized span file hashes to `0926a28ceba19870e91344ea8e1a44e165c6c96ec6c2d935340d93da147e7954`; the selected **full stored rows** file hashes to `3b023358c329640555ca01f170a2723f1ada080abcf7323fa2184a494a057c4f`. The original 360-row export remains unchanged.

### Capacity and source diversity

The selected section's planner-style input weight is **8,926 estimated tokens**. Its source density rate is 4 records per 1,000 input tokens, so the base estimate is `ceil(8,926/1,000 × 4)=36`. It contains 34 stored `table_row` spans and 17 `list_item` spans, giving a structural floor `ceil(34 × 1.5 + 17 × 0.5)=60`. The current risk estimate is therefore **max(36,60)=60 records**, within the pre-registered 50–60 band and **19 below max_records=79**. It meets the required `<65` readiness gate.

Deterministic source-signal scan of the exact selected source:

| Signal | Raw expressions/tokens | Approx. distinct notation |
|---|---:|---:|
| Currency | 58 | 47 |
| Percentages | 53 | 41 |
| Dates and time-bound values | 24 | 9 |
| Other numeric tokens, excluding the above matches | 97 | 74 |

These are source-density proxies, not a count of valid evidence. The section includes multiple substantive monetary claims, including donor contributions of **$230.0 million** and **$124.4 million** and reported partner income of **$1.584 billion**. Stored span types are 97 sentences, 34 table rows, 17 list items, 4 headings, and 2 sections. Heading/section spans are **6/154 (3.9%)** and comprise about **0.65%** of source characters, so the section is not dominated by that boilerplate proxy.

## Request construction and independence

The diagnostic harness loaded the exact full text and all 360 stored span rows into a fresh in-memory SQLite database, then called the real `AnthropicClient::extractChunk()` with a recording fake HTTP transport. The request retained the current prompt, schema, `span_reference` grounding, model `claude-haiku-4-5-20251001`, `max_tokens=16,000`, and `max_records=79`. No production prompt or configuration was edited.

The selected diagnostic chunk has no parent and no completed same-range ancestor. `IncrementalPipeline::continuationContext()` returned no records; the serialized user message contains **no `already_extracted` key**. It does not depend on earlier model output. The Control request body also has **no `temperature`, `top_p`, `top_k`, or `stop_sequences` keys**; absent means absent, not null.

Two independent PHP CLI processes, each booting Laravel and its own in-memory database, produced the same exact serialized request hash:

| Process | Request SHA-256 | Fake HTTP captures | Anthropic network calls | Before/after DB row counts |
|---|---|---:|---:|---|
| A | `143b3385379edaa0955625350eb69da3a59814ad23dffb0676bbf073ccfccd91` | 1 | 0 | Identical |
| B | `143b3385379edaa0955625350eb69da3a59814ad23dffb0676bbf073ccfccd91` | 1 | 0 | Identical |

Each process rolled back the diagnostic transaction. Queue dispatches and billing events were zero. The fake returned an empty response solely to let the production client finish request construction; it is **not** an extraction result.

## Four-call cost gate

The exact serialized request is **34,178 bytes**. No Anthropic token-count call was made, so input tokens remain an estimate. The current local fallback gives `ceil(34,178/3)=11,393` estimated input tokens. For a conservative ceiling, charge **one input token per serialized byte** and the full **16,000 output tokens per call**. Current configured Haiku rates are $1/M ordinary input, $5/M output, and $1.25/M cache-creation input.

| Four calls | Formula | USD |
|---|---|---:|
| Conservative, ordinary input | `4 × (34,178 × $1/M + 16,000 × $5/M)` | **$0.456712** |
| **Conservative, all input charged as cache creation** | `4 × (34,178 × $1.25/M + 16,000 × $5/M)` | **$0.490890** |
| Expected, ordinary input | `4 × (11,393 × $1/M + 8,752.5 × $5/M)` | **≈$0.220622** |
| Expected, all input charged as cache creation | `4 × (11,393 × $1.25/M + 8,752.5 × $5/M)` | **≈$0.232015** |

The expected output estimate uses the two previously observed Haiku leaf-2 usages, 10,036 and 7,469 output tokens (mean 8,752.5). It is empirical guidance from a different slice, **not a guarantee**. The higher conservative cache-creation estimate, **$0.490890**, is the cost gate value and is below $1.00.

## Artifact inventory and decision

All paths are under `docs/intelligence-v2/diagnostics/` and remain uncommitted:

- `unicef-safe-attribution-fixture.md` — this report.
- `unicef-safe-subchunk-scan.php` and `.json` — complete boundary scan and selected candidate.
- `unicef-safe-attribution-request-probe.php`, `unicef-safe-attribution-fixture-A.json`, and `unicef-safe-attribution-fixture-B.json` — fresh-process fake-transport checks.
- `unicef-safe-attribution-source.txt` — exact selected source substring.
- `unicef-safe-attribution-stored-span-rows.json` — selected rows with all stored fields.
- `unicef-safe-attribution-spans.json` — normalized structural span map used for hashing.
- `unicef-safe-attribution-payload.txt` — exact rendered span-labeled source payload.
- `unicef-safe-attribution-control-request.json` — exact serialized Control request body.
- `unicef-safe-attribution-signals.py` and `.json` — deterministic source-signal counts.

**READY as a diagnostic fixture only.** Estimated pressure 60 is below 65, there is no prior-model dependency, the two fresh-process request hashes match, the conservative four-call cost is below $1.00, Anthropic network calls are zero, and committed application-state changes are zero. No production planner behavior, code, prompt, schema, chunking, provenance, materiality, or B1 logic was changed. Nothing was pushed, merged, or deployed.
