# Collector experiment readiness — Phase 1C

**Decision: READY for an authorized, capped 12-call Control versus Collector study.** This is a readiness decision, **not authorization to send calls**. Provider calls made in Phase 1C: **0**. No push, merge or deploy. Branch `feat/intelligence-v2-stage-a`; fetched pushed tip and local HEAD `df53b7febd4a068355ce1655bc081a7737135497`. Existing Phase 1 diagnostic options are request scoped; production callers remain Control with prompt version `2`. `METADATA_ONLY` and `COLLECTOR_PLUS_METADATA` remain ineligible because null periods can collapse distinct metric identities.

## Real-path request gate

`extraction-experiment-probe.php` invokes the actual `AnthropicClient::extractChunk()` construction path with `Http::preventStrayRequests()` and fake Anthropic HTTP. It uses an isolated in-memory SQLite database, records counts before and after a rolled-back transaction, and fakes queue, bus, event, mail and notification dispatch. The matching `pdo_sqlite` extension was loaded from a package extracted under `/tmp`; no system PHP installation or remote database was changed. Each of the four types was built twice in **separate fresh PHP processes**. Both builds had identical SHA-256 hashes. Each process captured exactly one fake HTTP request, zero Anthropic network requests, equal before/after row counts, zero queue dispatches and zero billing events. The request JSONs are stored under `experiment-flags/requests/`; `real-path-gate.json` stores the gate results.

| Frozen study chunk | Variant | Exact request SHA-256 | Bytes | Prompt version |
|---|---|---|---:|---|
| UNICEF E100–E139 | CONTROL | `d304bc2997f958dc6784390c62266758571ad87fcca391a1fc9c68fbc4d296fe` | 13,676 | `2` |
| UNICEF E100–E139 | COLLECTOR_ONLY | `e7e913c49784741f3a966ce6eea45cdbc18d29655f5b38941e015ee747990ae3` | 13,780 | `diagnostic-collector-v1` |
| India E200–E238 | CONTROL | `f3f0f6f7313153c1e8693d2fe5ad3470d46425ec43af8c0277512af40bcc7669` | 14,360 | `2` |
| India E200–E238 | COLLECTOR_ONLY | `12d55bc7b35a631fef9305eb20a46a07e3b9075675e9131dd44edc9860a4feeb` | 14,464 | `diagnostic-collector-v1` |

For each chunk, decoded Control and Collector bodies are identical after replacing **only** `system[0].text`. Prompt version is recorded separately; it is not serialized into the provider body. Model `claude-haiku-4-5-20251001`, source spans, user envelope, schema, `max_tokens=16000`, `max_records=79`, grounding, document metadata, cache control and omitted sampling keys are unchanged. The two source and span-map hashes within each pair are identical. Any future change to a frozen request body requires a new readiness gate and benchmark version.

## Per-call execution safeguards (frozen before any paid call)

`experiment-flags/collector-study-run.py` is the guarded future runner. It requires an explicit `--execute` argument and an API key; neither was supplied or run in this phase. For **each** scheduled cell, it reads the frozen serialized request bytes that will be posted, computes SHA-256 immediately before transport, and compares against a separately hard-coded frozen hash for that exact chunk and variant. It posts those **same verified bytes**, without reserialization. Any mismatch stops before HTTP and writes `study-stop.json` with **REQUEST_DRIFT**, expected hash and actual hash. No cell can proceed on an unverified body.

The runner maintains `paid-study/ledger.json` after each attempt. Before every next call it checks known usage-based actual cost **plus** a conservative reserve for any failed call with unknown usage **plus** the next call's frozen conservative bound against **USD 1.50**. A possible overrun stops before HTTP and writes **BUDGET_STOP**. It does not change prices, token assumptions or the ceiling mid-run. `ANTHROPIC_API_KEY` is read only by the explicitly executed runner and never written to artifacts.

Each cell has one request artifact, an exact response artifact when received, and metadata with request/response hashes, HTTP status, provider request ID, UTC start, stop reason, usage, latency and cost. No retries and no replacement calls. A failed call keeps its artifact and leaves its exact cell missing; the runner advances in the frozen order after one transport or malformed-response failure. **Two failures**, or **any non-200 HTTP**, stop immediately after artifacts are preserved and report **STUDY_INCOMPLETE**. One failed call with all later calls completed yields `STUDY_COMPLETE_WITH_MISSING_CELL`; analysis must display that missing cell and must not invent a complete three-run cell mean. A partial request artifact without metadata blocks resumption because sending status is uncertain. A prior stop file also blocks resumption. A max-token stop reason is classified by the truncation rule below, not retried.

Offline tests with an injected fake transport verified exact hashes, budget stop before transport, one preserved missing cell, immediate non-200 stop, two-failure stop and no resend on resumption. No provider call was made.

## Frozen chunks and output pressure

The original UNICEF candidate **E033–E186 is excluded**: prior Control calls used as many as about 13.6k of 16k output tokens, leaving too little headroom for a collector that may return more observations. Before any new provider output, it was replaced with the span-aligned **E100–E139** segment. The replacement covers cross-country operational results, percentages, currency, time bounds and ordinary programme facts; it is not dominated by footer or navigation text. India **E200–E238** remains the distinct India sanitation and water case study. Both labeled source files are frozen under `experiment-flags/`, with offsets in `study-chunks.json` and byte hashes in `frozen-hashes.json`. The table hashes the labeled fixture bytes; `real-path-gate.json` separately hashes the raw slice and span map used by `AnthropicClient`.

| Chunk | Source offsets | Spans | Source SHA-256 | Source-side expected records / output tokens | Risk decision |
|---|---:|---:|---|---:|---|
| UNICEF replacement | 11,115–18,403 | E100–E139 (40) | `103987d34dc9a0a55d6d6924273b19177e60f53f6db67ff7a5924b00c4b7d46a` | 11 / 1,714 | Accepted with truncation hard gate |
| India WASH | 32,299–40,228 | E200–E238 (39) | `0709f82d8ff76517e2617ca88e17a1e21a4bbefbcd60a2c18c678ee50b23171f` | 12 / 1,864 | Accepted with truncation hard gate |

The estimates come from the current `ProactiveChunkRisk::assess()` on the frozen source and spans. They are planning estimates, not guarantees. UNICEF replacement had 2,563 estimated input tokens, 3 table rows, 10 list items, and numeric ratio 0.077; India had 2,778, 3, 2 and 0.025 respectively. Both have `output_saturation_risk=false` against a 12k safe-output estimate. The full earlier UNICEF segment had source-side pressure 60 and observed response pressure much higher, so the replacement is intentionally much smaller. No `max_tokens`, model, temperature or chunking configuration was changed.

### Truncation rule

A call is **TRUNCATED** when `stop_reason` indicates max-token or output-length termination. Any Collector truncation on a chunk where all three Control calls complete normally is a **hard capacity failure** for that chunk. If both variants truncate, that chunk cannot support an extraction-quality recommendation. If neither truncates, compare normally. Missing late-source evidence in a truncated call is **not** classified as model omission. The study uses one provider request per scheduled call: no automatic continuation or silent retry. If truncation occurs, retain the exact partial response and mark the call; do not add a thirteenth call.

## Benchmark provenance and immutable sets

The gold/negative files were curated by the Codex agent through direct reading of the frozen source spans, and are human-verifiable but have **not had independent human adjudication**. The original UNICEF gold work was done during earlier **post-call source review**; the E100–E139 replacement set was rebuilt and extended by Codex on **2026-10-09 at about 10:24 UTC, before any Collector output**, using source claims within the new span range. It is **NOT BLIND** to the earlier UNICEF Control/temperature audit. Interpret its recall direction with that limitation. The India set was created by Codex from source text on **2026-10-09 at about 10:12 UTC**, and split into separately hashed files at about 10:22 UTC, **before any Collector experimental output**; the prior E033–E186 provider responses do not cover India E200–E238. The agent had already seen previous model outputs for the separate UNICEF E033–E186 slice when creating India, so it was created **after some previous model outputs were seen**, but **before any model output for India E200–E238 or this Collector study**. It is blind to the proposed experiment's outputs, though not an independently adjudicated human benchmark. Selection procedure: read each exact span, select independently supported monetary claims, percentages, time bounds, major operations and several ordinary facts; record canonical value and explicit-period support; separately list axis/header noise, unsupported periods, target/actual confusion and false deadline interpretations. No clear contractual deadline was present in either final slice, so none was invented.

| Chunk | Gold count / SHA-256 | Negative count / SHA-256 |
|---|---|---|
| UNICEF E100–E139 | 17 / `2f3ff579f519ebf7fd42fd9a1d3fbc2b1482a61d4cc0cbfabc8d96356342b0f0` | 6 / `d7ae58df69ae7e3ba33530b021d8fc856513d077ed2d6effa6faf57041de479a` |
| India E200–E238 | 12 / `69b89cfd09c2807834f7e914d92fb3181b57794c343cc7ae8893602b4fa1b0ad` | 5 / `fcf8a0655919ec96f0f1737c136bbce99ae816911329790818ead06a51dc7147` |

These are hashes of the standalone `*.gold-items.json` and `*.negative-items.json` bytes. Freeze these exact files before any provider call. A later genuine annotation error must be recorded as a separate correction with the original preserved; it cannot silently rewrite the benchmark. **Always report UNICEF and India recall separately.** A combined descriptive summary may follow the separate results; no pooled opaque recall score.

## Blind precision review

For every successful call, sample **exactly 20 accepted records**, or all accepted records if fewer than 20, uniformly without replacement using seed **20261009**. The authoritative `experiment-flags/precision-review.py prepare` command uses `random.Random(20261009).sample(range(n), min(20,n))` on each call's accepted records in accepted order, combines those samples, then deterministically shuffles the combined packet with the same seed. It assigns neutral `S0001`-style IDs. It writes `precision-review-blinded.json` with only the extracted claim/record, source citation/span and exact relevant frozen source text, plus a separate `precision-review-private-mapping.json` with cell, variant, run and accepted index. Save both before review and withhold the private mapping and identifying filenames from the reviewer. The blind packet excludes variant, run number, prompt version, request hash and provider request ID. The reviewer sees no study labels during scoring.

The reviewer writes `precision-review-judgments.json` with exactly one allowed judgment per blind sample ID. Before opening the private mapping, `precision-review.py freeze` validates the IDs and judgments, computes the judgment-file SHA-256 and writes that hash to `precision-review-report.json` with `mapping_applied=false`. Only `precision-review.py unblind` may join the mapping; it refuses a changed judgment file or blind packet and writes per-chunk, per-variant and per-cell precision to a separate artifact. No judgments or hash exist yet because the paid study has not run. The reviewer must not inspect the mapping before the judgment hash is frozen. The wider-experiment agent's access to study context is disclosed exactly as follows: **“The precision reviewer was an AI agent using mechanical blinding. The agent conducting the wider experiment had access to study context, so this is not equivalent to an independent human-blinded review.”** A human may optionally spot-check 10–15 sampled records after the AI hash is frozen; preserve those human judgments separately and do not overwrite the AI review.

Classify each sampled record as:

- **SUPPORTED:** cited source supports the claim and structured metadata.
- **PARTIALLY_SUPPORTED:** core claim supported, but noncritical metadata unsupported or overstated.
- **UNSUPPORTED:** claim materially unsupported by the citation.
- **NOISE:** technically sourced but not useful evidence, such as chart-axis artifacts, isolated labels or context fragments.

Primary sampled precision numerator is **SUPPORTED**; denominator is all sampled accepted records. Also report `(SUPPORTED + PARTIALLY_SUPPORTED) / sampled` separately. Negative-set matches count independently as false positives, even if also sampled for precision. Record every judgment and source span before unblinding.

## Metrics and numeric decision rules

Report, per chunk and per run: gold recall (count and rate), priority/material gold recall, sampled precision, negative-set false positives, returned and accepted counts, validator rejection rate, grounding-failure rate, `origin=document` and `origin=unknown` shares, key-figure eligibility, stop reason/truncation, output tokens, usage-based cost, and pairwise cross-run Jaccard plus spread. Rejection rate = rejected / returned; grounding-failure rate = grounding rejects / returned; negative false-positive rate = matched negative items / frozen negative items. Treat zero denominators as unavailable, never as zero failure. A gold item counts recalled only if an accepted finding supports its normalized claim with the required cited span(s) and value; do not credit a mere number match. Each run's recall is counted against its chunk's frozen set.

Effects are **directional** unless consistent across **both chunks** and all three runs per variant where applicable. Collector is a candidate improvement only when **all hard gates** pass:

1. Mean gold recall count is at least Control on **both** chunks, and improves by at least **one gold item** on at least one chunk; no chunk may be lower by more than one item. For a further-productization recommendation, at least one chunk must show a consistent run-level gain.
2. Collector `SUPPORTED` sampled precision is no more than **5 percentage points** below Control on either chunk. Report partial-support precision separately.
3. Mean negative-set false-positive rate rises by no more than **5 percentage points**. Any repeated new false positive in all three Collector runs and zero Control runs is a specific warning, even if the aggregate threshold passes.
4. Validator rejection rate rises by no more than **5 points** and grounding-failure rate by no more than **2 points**.
5. `origin=document` deterioration over **10 points** is a safety concern. Origin shares and key-figure eligibility are secondary descriptive measures; unchanged values do not fail Collector, which does not change metadata discipline.
6. No Collector-only truncation where all Control calls on that chunk complete normally.
7. Collector mean usage-based cost per successful call rises by no more than **25%** versus Control on either chunk.

Report Jaccard and spread, but stability alone is not a success gate. **RECOMMEND COLLECTOR FOR FURTHER PRODUCTIZATION** only if every safety gate passes, both chunks preserve or improve recall, at least one shows a consistent gain, precision remains within threshold, and no capacity failure occurs. **MIXED / MORE STUDY** if one chunk improves while the other is effectively unchanged, run effects vary materially, precision/cost approaches a threshold, or nonblind gold provenance weakens interpretation. **REJECT COLLECTOR** for material recall loss on either chunk, precision loss over 5 points, repeated new negative findings, Collector-only truncation, cost rise over 25%, or failed grounding/rejection thresholds. Do not force a positive recommendation.

## Authorized ceiling calculation, not call authorization

Configured `claude-haiku-4-5-20251001` pricing (`config/document_intelligence.php`) is **USD 1 / million input**, **USD 5 / million output**, **USD 1.25 / million cache-write**, **USD 0.10 / million cache-read** tokens. Conservative bound charges **every input byte of the exact frozen request as 1.25 input tokens**, then uses the more expensive cache-write rate of USD 1.25/million for *all* those tokens. It charges the full `max_tokens=16000` at USD 5/million for every call, with no cache saving. This deliberately overstates input tokenization and ignores possible cheaper reads. Three calls per request type yield:

| Request type | Input charge bound | Output charge bound | Three-call bound |
|---|---:|---:|---:|
| UNICEF Control | $0.06410625 | $0.24000000 | $0.30410625 |
| UNICEF Collector | $0.06459375 | $0.24000000 | $0.30459375 |
| India Control | $0.06731250 | $0.24000000 | $0.30731250 |
| India Collector | $0.06780000 | $0.24000000 | $0.30780000 |
| **Total maximum conservative estimate** | **$0.26381250** | **$0.96000000** | **$1.22381250** |

The pre-set study budget ceiling is **USD 1.50 total**. The bound leaves **USD 0.27618750** headroom. Do not reinterpret the ceiling once calls begin. Twelve calls maximum, one request per scheduled call; no silent retries or extra continuation calls. If frozen request bytes or pricing change, recompute and repeat readiness before any call.

## Exact future call order and capture fields

1. UNICEF C1
2. UNICEF K1
3. INDIA C1
4. INDIA K1
5. UNICEF C2
6. UNICEF K2
7. INDIA C2
8. INDIA K2
9. UNICEF C3
10. UNICEF K3
11. INDIA C3
12. INDIA K3

`C=CONTROL`; `K=COLLECTOR_ONLY`. This interleaves variants to reduce provider drift. Before each call, verify the exact request hash against the table and unchanged benchmark hashes. Record provider request ID, exact request and response bytes/hash, UTC start timestamp, stop reason, input/output/cache creation/cache read tokens, latency, usage-based cost, returned/accepted/rejected/grounding counts and prompt version. No silent retry. Current production sampling behavior applies to both variants: no temperature, top-p, top-k or stop-sequence fields are added.

## Readiness checklist

Real path, isolation and repeated four-type hashes: **PASS**. Per-call hash, budget and failure safeguards: **IMPLEMENTED AND OFFLINE TESTED**. Mechanical blind review and judgment hash lock: **IMPLEMENTED AND OFFLINE TESTED**. Frozen span sources and source-side capacity assessment: **PASS**. Gold/negative sets, hashes and provenance: **PASS, with disclosed nonblind UNICEF limitation**. Blind precision protocol, numeric thresholds, call order and truncation rule: **FROZEN**. Conservative total $1.22381250 ≤ $1.50: **PASS**. Provider calls so far: **0**. Production default changes in Phase 1C: **0**. Decision: **READY for a separately authorized paid study**; no paid call was made.
