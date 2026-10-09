# UNICEF leaf 2 request-path determinism control

**Scope.** This is a zero-Anthropic, diagnostic-only control for the small root-cause experiment. It does **not** replay an actual provider response or attribute extraction losses. No production code, prompts, schemas, config files, or application data were changed.

## Environment snapshot

| Item | Value |
|---|---|
| Branch / HEAD | `feat/intelligence-v2-stage-a` / `aaa90fc715268cebfae15e181d0e9b21cd64c8a8` |
| Working tree before probe | Dirty: uncommitted diagnostic report and local `unicef-leaf1.txt`; no tracked production diff |
| Operating system | Linux `7.0.0-29-generic`, x86_64 |
| PHP / framework | PHP 8.5.4 CLI / Laravel 13.14.0 |
| PHP timezone / locale / encoding | UTC / `C.UTF-8` for character type, `C` for remaining locale categories / UTF-8 |
| Source | `unicef-leaf2.txt`, 37,584 bytes, 37,196 characters, SHA-256 `5e9e9609210a288163288a1d64a9ffd5a153116d58f2ec201f6f76a0944ee023` |
| Diagnostic database | Fresh in-memory SQLite for **each** PHP process; PHP SQLite extension loaded from a package unpacked into `/tmp`, not installed systemwide |
| Provider/client | Real `AnthropicClient::extractChunk()` with Laravel HTTP fake and `Http::preventStrayRequests()`; fake response only |
| Model / max_tokens | `claude-haiku-4-5-20251001` / 16,000 |
| Planning budget / record size / max_records | 12,000 output tokens (`16000 × 0.75`); 150 tokens per record; `floor((12000 − 64)/150) = 79` |
| Chunk planning | `chunk_max_tokens=null`; overlap 400 tokens; minimum split 2,000 characters |
| Density/context planning | Prose 4 and dense 6 estimated records per 1,000 input tokens; dense numeric threshold 0.18, dense tabular-line threshold 0.30; context safety ratio 0.10 |
| Span settings | min 50, max 400, hard max 1,200 characters; max evidence IDs 3; locality 12 spans; segmenter version `1` |
| Grounding | Live new-pipeline flag `document_intelligence.evidence_spans=false` in this local environment; diagnostic document's stored `ai_pipeline.grounding` is explicitly `span_reference`, matching the historical route |
| Other route flags | `incremental=true`, pipeline version `1`, prompt version `2`; extraction concurrency 2; provider max inflight 2; continuation quote limit 160 characters and maximum 2 truncation continuations |
| Material environment overrides | `ANTHROPIC_EXTRACTION_MODEL`, `ANTHROPIC_EXTRACTION_MAX_TOKENS`, and `DOCINTEL_EVIDENCE_SPANS_ENABLED` unset locally, so inspected config defaults apply; the diagnostic explicitly pins model and stored span grounding |
| Diagnostic side-effect controls | In-memory provider gate; array cache; fake bus, queue, events, notifications, mail; AI-credit enforcement disabled in the isolated harness |

The previous stability analysis was recorded at commit `8c02050914b1c9821c8cd7afe2bf7a2d70911dbc`. The current HEAD adds only the historical analysis artifacts and source file; the extraction code/config files in this path have no diff against that commit. The harness uses a surrogate document name and an isolated leaf-level span version, so its body is **not** claimed to be the historical request body.

## Independent process runs and clock conditions

Each invocation booted Laravel and its own in-memory database in a **separate PHP CLI process**. Artifact comparison happened after all six processes exited. Normal runs used the real clock. Frozen runs used `2026-10-09T00:00:00+03:00`.

| Clock | Fresh process A | Fresh process B | Fresh process C |
|---|---|---|---|
| Normal | `002cd6c5…1289cd0` | `002cd6c5…1289cd0` | `002cd6c5…1289cd0` |
| Frozen | `002cd6c5…1289cd0` | `002cd6c5…1289cd0` | `002cd6c5…1289cd0` |

The **full serialized outgoing body SHA-256** in every run was `002cd6c5ab049418183b9039b27f23c94973af5632109dc0259d361751289cd0`. The labeled-source payload hash was `da16add775121ac39d2fbbacd2ccd3beb8d32d2bfea331184d1fa2b241165463`; the regenerated span-map JSON hash was `07d4884b8761adac8423df88a1406cc369126b31a04f2d5a80fc21837ecec614`. These hashes were identical across normal and frozen conditions. **No time-dependent request-body nondeterminism was observed in this isolated path.** This says nothing about provider output or later evidence processing.

The body was captured as `Illuminate\Http\Client\Request::body()` inside the fake transport called by the real `AnthropicClient` method. Top-level keys were exactly `model`, `max_tokens`, `messages`, `system`, `output_config`. The keys `temperature`, `top_p`, `top_k`, and `stop_sequences` were **ABSENT**, not present with null values. The user envelope carried `max_records=79`. The body was 48,679 UTF-8 bytes in the diagnostic reconstruction.

## Time, randomness, and ordering audit

| Path | Occurrence | Potential effect on request body |
|---|---|---|
| `SourceSpanBuilder`, `EvidenceSpanSet`, `ChunkPlanner`, `ExtractionCapacity`, `EvidenceSchema` | No clock or random source in span segmentation, rendering, planning formulas, or schema/instruction construction; `ChunkPlanner` sorts candidate cuts with `usort` | Deterministic for fixed source and config |
| `EvidenceGrounding::persist()` | `now()` and `Str::uuid()` create row metadata; the subsequent span load has explicit `orderBy('ordinal')` | UUID/time do not enter rendered IDs/text; probe preloaded spans so this write path was not exercised |
| `IncrementalPipeline::continuationContext()` | Traverses parent chain and saved record arrays in their stored order; no clock/random call | Probe has no continuation ancestors |
| `IncrementalPipeline` job/admission path | `now()` for status/queue timing; some queries explicitly order by `created_at,id` or `start_offset`, while progress/recovery `get()` queries lack order | Metadata and scheduling can vary; direct probe did not execute job planning/dispatch |
| `DocumentChunk::issueDispatchToken()` | `Str::uuid()` and `now()` | Job-dispatch metadata; not invoked by the direct request probe |
| `AnthropicClient::throttle()` | `now()` sets a cache expiry | Admission timing only; array cache is fresh per process |
| `ProviderGate::acquire()` | Two `Str::uuid()` values, `microtime()`, `hrtime()`, and `random_int()` for poll jitter | Permit identity/waiting only; memory gate admitted immediately in probe |
| `AnthropicClient::callWithRetry()/recordAiRun()` | `hrtime()` and `now()` in response telemetry/audit row | Audit metadata varies, but is absent from request body; row was rolled back |
| `OperationSpend::activeQuote()` | Unordered `get()` followed by sort on status, kind, and timestamp; ties may retain DB iteration order | Disabled in isolated harness; potential quote-selection tie is outside request body |

No `rand()`, `mt_rand()`, `random_bytes()`, `Str::random()`, or `Str::orderedUuid()` occurrence was found in the inspected planner/span/request path. The full production worker path was not run, so this audit does not establish deterministic scheduling or persistence metadata.

## Zero-network and persistence proof

Each process captured exactly **one fake HTTP request** to `https://api.anthropic.com/v1/messages`. The fake throws for any other URL and `Http::preventStrayRequests()` blocks unmatched requests. **ANTHROPIC_NETWORK_CALLS = 0** in all six runs. The returned `{"records":[]}` is a local fixture, not a provider output.

The harness created four fixture tables plus seven empty tracked side-effect tables before its baseline snapshot: `documents`, `document_chunks`, `document_source_spans`, `document_ai_runs`, `document_evidence`, `processing_jobs`, `jobs`, `billing_operations`, `operation_quotes`, `workspace_credits`, and `audit_logs`. Before/after row counts were identical in every process. Baseline counts were 1 document, 1 chunk, 211 source spans, and 0 in each other tracked table.

**DATABASE_WRITES = nonzero attempted:** one expected `INSERT` into `document_ai_runs` per process from the real client audit path. Each request ran inside a transaction that was rolled back; **committed row-count delta = 0** in every tracked table. There were **QUEUE_DISPATCHES = 0**, **BILLING_EVENTS = 0**, and **OTHER_SIDE_EFFECTS = none observed**. Fake bus, queue, notification, and mail assertions passed. No unexpected write occurred. These are isolated SQLite facts, not a claim that the production job performs no writes.

## Historical comparison

| Check | Available comparison | Agreement |
|---|---|---|
| Exact historical payload hash | Historical payload not saved in repository bundle | **NOT EVALUABLE** |
| Exact historical span-map equality | Full historical span map not saved | **NOT EVALUABLE** |
| Span count | Regenerated isolated leaf: **211**. Historical document: **360 total**; saved diagnostic has no explicit leaf-only count | Partial context only |
| First/last offset | Regenerated leaf spans cover local `0–37196`; translated by historical leaf start `20821` they cover `20821–58017` | Matches known chunk bounds; not proof of each span offset |
| Page bounds | Regenerated pages **10–22** | Matches known historical leaf pages |
| Span IDs | Regenerated `E001–E211`; saved historical cited IDs range `E150–E352`. Under a **hypothetical +149 ordinal shift**, all 215 cited span/type pairs in the persistent historical analysis match regenerated span types | Strong partial agreement; no exact ID/map proof |
| Ordering | Regenerated map is ordinal and stable across six processes; historical full ordering unavailable | Partial |
| Chunk boundary | Exact source file hash and translated `20821–58017` boundary | Matches recorded leaf boundary |

**Historical reproduction rating: C — NOT REPRODUCIBLE WITH AVAILABLE DATA.** The 215 type matches and boundary agreement support reconstruction feasibility, but the historical labeled payload, full span offsets, document name, and byte-level request hash are unavailable. The current harness must not be substituted for the requested four-call attribution experiment.

## Artifacts and next gate

The uncommitted [diagnostic harness](unicef-request-probe.php) and six JSON artifacts in `docs/intelligence-v2/diagnostics/unicef-request-probe/` contain the exact captured bodies, span maps, hashes, and before/after counts. The original [small root-cause audit](unicef-small-root-cause-audit.md) remains stopped at **0 of 4 Anthropic calls**. A paid run still requires recovering the historical request metadata/span map and completing the cost gate. No attribution bucket or C-versus-T0 conclusion is available.
