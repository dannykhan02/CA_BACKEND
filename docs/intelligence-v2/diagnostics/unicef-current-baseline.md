# UNICEF current extraction baseline — BLOCKED

This is a diagnostic of the current code path using authentic stored UNICEF source text and source spans. It does not reconstruct either historical request and makes no provider call.

## Artifact and environment gates

- Repo: `CA_BACKEND`; branch: `feat/intelligence-v2-stage-a`; HEAD: `aaa90fc715268cebfae15e181d0e9b21cd64c8a8`. Working tree has uncommitted diagnostic artifacts; no tracked production-code change.
- The requested `/mnt/data` paths were absent in this execution environment. Byte-identical files with the requested names were available at the repository root and were independently checked before planning.
- Full text: `unicef-full-extracted.txt`, **58,536 bytes**, **58,018 Unicode characters**, SHA-256 `c919b4bb36fb3467906cc176a72d57c422260d6a17bbb4f4affc05c93415015c`. **VERIFIED.**
- Stored span export: `unicef-source-spans.json`, **166,685 bytes**, **360 rows**, SHA-256 `df213788ebdc82a9fa98280cc47eed71c2f90862e027fe02b42fbd0882021b55`. **VERIFIED.** Rows belong to document `01a11cf0-4071-737b-b92a-f4a55c1053d8`, extraction version `8acd7d011fac21b31b65c5dfa251423d`. E001 begins at character offset 1/page 1; E360 ends at offset 58,017/page 22. Stored offsets and pages were used as supplied, with no span regeneration.
- Production runtime, as verified in the prior phase and supplied for this phase: `DOCINTEL_EVIDENCE_SPANS_ENABLED=true`, `config('document_intelligence.evidence_spans')=true`, `app()->environment()=production`. Stored pipeline: `grounding=span_reference`, `route=incremental`, model `claude-haiku-4-5-20251001`, `record_limit=79`, prompt version 2, pipeline version 1. **PRODUCTION_GROUNDING_ROUTE = VERIFIED.** `IncrementalPipeline::start()` fixes grounding in `ai_pipeline`; `EvidenceGrounding::mode()` and `AnthropicClient::extractChunk()` subsequently use the stored choice.

## Planner scope and limitations

`IncrementalPipeline::start()` uses stored `ai_pipeline.tokens` and its `count_method` when present. Those two stored fields were not in the supplied export. The diagnostic therefore used the **current production fallback estimator**, `ChunkPlanner::estimate(full source) = ceil(58,536/3) = 19,512` tokens, and passed the exact stored `EvidenceSpanSet` to `planFromSpans()` with overlap enabled. `ExtractionCapacity::decide()` returned partition budget 19,750 tokens, direct capacity mode, and record limit 79; the span-aware planner nevertheless produced two root chunks because labeled span payload weight exceeds one partition. This is the exact current code path **under its documented fallback input**, not a claim that an unavailable production token count is known.

At runtime, `ProcessDocumentChunkJob::process()` separately calls Anthropic's token-count endpoint and, if it succeeds, can proactively split a structurally dense root before extraction. That call was prohibited here. The second root has local split risk, so any children remain conditional and are not represented as actual chunks.

The pre-registered source-side study rubric was applied in order: (1) independent request with no prior model records, (2) currency/percent/date/operational diversity, (3) enough multi-value density, (4) low boilerplate, (5) **estimated record pressure ≤65**. Counts below are deterministic regex proxies, not qualifying-evidence counts. Currency and percent figures are approximate distinct notation counts; dates include year and time-bound expressions; other numeric counts subtract numeric tokens appearing in matched currency/percent/date expressions. Boilerplate proxy is heading/section spans divided by included spans. Source slices overlap, so counts must not be summed as document totals.

| Root chunk | Character offsets (end exclusive) | Pages | Spans | Source bytes / SHA-256 | Rendered payload SHA-256 | Control request SHA-256 | Est. records | Currency distinct | Percent distinct | Date/time distinct | Obligation phrases | Other numeric approx. | Boilerplate span ratio | Prior-model output? |
|---|---|---|---|---|---|---|---:|---:|---:|---:|---:|---:|---:|---|
| 0 | 1–29,519 | 1–12 | E001–E186 (186) | 29,702 / `87b3bc8a556a45ecff55f4cfe41e8ac63fd45399c6001aee9b175c1e6d0862dc` | `f9ba6e62956368fd55e2b46fa82393f1399dd956cf32b48ed09b4864b2d9eb1f` | `c33d976887f81a60277bc9ce92830de4abfba8a8f19d47aab191ee7772a08532` | **67** | 49 | 41 | 7 | 1 | 102 | 3.8% | No |
| 1 | 28,597–58,017 | 12–22 | E183–E360 (178) | 29,764 / `b2d9a8d8fadbc9941669b19eb5f8ee69bcbf90044937ad7b83f1ba0542fd91be` | `187ead5ef454969c39ec29514c950effc74e1d3f7c993cce45d6708ea200ce3c` | `5271d1aec5c3ba94d013f9ca0e995c2ea64e02dc37491ed30873c17861ea60b0` | **84** | 30 | 20 | 6 | 0 | 102 | 4.5% | No |

Source-side planning pressure uses the current `ProactiveChunkRisk::assess()` formula: `max(ceil(input_tokens / 1000 × records_per_1k_tokens), ceil(table_rows × 1.5 + list_items × 0.5))`. For chunk 0, planner input weight 10,432 tokens, 39 table rows, 17 list items yields `max(42,67)=67`. For chunk 1, 10,431 tokens, 52 table rows, 11 list items yields `max(42,84)=84`. The latter also exceeds the 12,000-token safe output estimate (84 × 150 + 64 = 12,664); the former estimates 10,114. Neither meets the pre-registered ≤65 ceiling. Both contain diverse claims and little heading/section boilerplate, but capacity safety controls selection. **No study chunk was selected.**

## Continuation and request diagnostics

`IncrementalPipeline::continuationContext()` walks only completed, same-range ancestors. Both planned roots have no parent and therefore no previous model records. A future continuation of a truncated response can carry `already_extracted` and would be ineligible. A proactive split child has a split parent, not a completed same-range ancestor; its source-side request would not need prior model records, but its existence depends on the prohibited token-count path.

The real `AnthropicClient::extractChunk()` request construction was run against a fresh in-memory SQLite database containing the exact stored span rows and a fake HTTP transport. The transport captured the outgoing body and returned an empty diagnostic response; it never transmitted a request. Both root requests used model `claude-haiku-4-5-20251001`, `max_tokens=16,000`, `max_records=79`, and omitted `temperature`, `top_p`, `top_k`, and `stop_sequences` **as keys**, not merely as null values. Neither had `already_extracted`. Each probe rolled back its transaction; before/after row counts matched across all harness tables. Queue and billing side effects were faked/disabled.

The chunk 0 request was additionally constructed in **two independent PHP CLI processes**. Both produced SHA-256 `c33d976887f81a60277bc9ce92830de4abfba8a8f19d47aab191ee7772a08532`. This checks request reproducibility for that root, but it does **not** satisfy the selected-study-chunk gate because chunk 0 estimates 67 records.

Reproduction artifacts: `docs/intelligence-v2/diagnostics/unicef-current-plan-preflight.php`, `unicef-current-plan-preflight.json`, and `unicef-current-request-probe.php`. Per-chunk stored-span-map hashes from the probe are `7979f0e9454fc914ca4b104dd0c2536a754129ea7ae89bae9b3f9b0f8d30766b` (chunk 0) and `ff0773c2e4705cc9e279434a2f740c70d12b5903672d54cbbdc2208a87075e14` (chunk 1).

## Cost diagnostic

There is no selected eligible request, so the formal selected-request cost gate is **NOT EVALUABLE**. For scale only, chunk 0's exact serialized body is 39,140 bytes. The production offline token fallback is `ceil(bytes/3) ≈ 13,047` input tokens; it is an estimate, not a provider count. A conservative upper bound of one token per serialized byte gives four-call cost `4 × (39,140 × $1/M + 16,000 × $5/M) = **$0.47656**`, below $1.00. Using 13,047 estimated input tokens and the two prior valid Haiku leaf-2 output usages (10,036 and 7,469; mean 8,752.5) gives an **approximate** four-call expectation of **$0.22724**. Those prior outputs came from another slice/prompt context and cannot establish this root's actual usage. These figures do not waive the ≤65-record condition.

## Readiness

**BLOCKED.** Artifacts, production grounding, stored span metadata, root independence, zero-network request construction, and a two-process root request hash check pass. **Neither current fallback-planned root is safely below the pre-registered 65-record pressure threshold (67 and 84).** Runtime token counting may proactively split chunk 1, and unavailable stored token-count metadata could affect the root plan; neither can be treated as verified without a new authorized input. No study chunk, selected-request hashes, or selected-request cost gate is certified.

**ANTHROPIC NETWORK CALLS = 0. COMMITTED APPLICATION-STATE CHANGES = 0.** Diagnostic PHP/JSON/Markdown artifacts remain uncommitted. No production code, prompt, schema, chunking, provenance, materiality, or B1 setting was changed; nothing was pushed, merged, or deployed.
