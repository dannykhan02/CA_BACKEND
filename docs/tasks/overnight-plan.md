# Overnight plan: AI cost control (2026-10-06)

Branch: `chore/ai-cost-control` (current branch; no new branch, no push).
Source brief: `docs/tasks/cost-control.md`, with the unattended overrides from the overnight request.

## Phase 0: measurement status

**Usage IS persisted per call** in `document_ai_runs`: `document_id`, `workspace_id`, `purpose`,
`model`, `input_tokens`, `output_tokens`, `cache_creation_tokens`, `cache_read_tokens`, `chunk_id`
(leaf), `request_attempt`, `status`, `failure_class`, `duration_ms`, `estimated_cost_usd`,
`provider_request_id`. Leaf outcomes are in `document_chunks` (`status`, `failure_class`, `depth`,
`parent_id`, `attempts`, `token_count`, `overlap_chars`, `reserved_cost`, `cost_accounting`).
Document size and route are in `documents.ai_pipeline` (`tokens`, `mode`, `routing`, `analysis_revision`).

**The measurements could not be run tonight:**
- The local dev database (port 5432) is down. Starting it needs sudo, which this unattended run cannot use.
- The October 3-5 spend happened on production (Railway). This run must not make network calls.
- Reading `.env` is forbidden, so the deployed model variables cannot be inspected either.

The smallest fix is to make the measurements one read-only command away: **Workstream 3** adds
`php artisan docintel:ai-usage-report {document?} {--days=7}`, which prints every Phase 0 table from the
persisted data. Run it in the morning (production: through `railway ssh`, one command at a time).

### Modeled measurement for the known case (from the verified sector_report counts, not from the DB)

| Item | Old routing (the Oct 5 run) | Current routing (committed redesign) |
|---|---|---|
| Document tokens | 62,125 | 62,125 |
| Provider extraction calls | 74 (28 completed + 32 split parents + 14 split_limit; 10 budget leaves never called) | 4 planned (coarse) |
| Calls that ended in truncation and were discarded | 46 (every split parent and split_limit leaf) | 0 expected; at most 2 x roots allowed (WS4) |
| Output tokens billed and discarded | about 46 x 4,096 = **~188K** | 0 expected |
| Input re-sent by recursive splitting | about 3-4 x the document (~190-250K) | about 1.02 x (overlap only) |
| Amplification (provider tokens / document tokens) | about **7-8x** | about 1.7x including the Sonnet synthesis |
| Extra spend per user re-analysis | 24 reopened leaves (14 split_limit + 10 budget), most truncating again | only failed leaves reopen |

Overlap: 400 tokens per partition boundary, so a 4-partition 62k document re-sends about 1.2K tokens (about 2%). Splits use no overlap.

## Phase 1: root causes, ranked by modeled share (to be confirmed by the usage report)

1. **Truncated extraction responses billed and discarded, then re-sent via splitting** (the largest share; output tokens cost 5x input on Haiku).
   - Old cap: 4,096 output tokens against about 8K tokens of needed output per 14K-token chunk.
   - Every `max_tokens` stop billed the full 4,096 output tokens, threw the partial JSON away, and re-sent the text as two children.
   - This matches "output tokens roughly equal to input tokens" on Oct 5.
   - *Already fixed in code* by the capacity-aware routing (16K cap, partitions sized to expected output).
   - *Remaining gap:* nothing bounds the total number of splits per document. Only depth (4) and minimum child size bound it per branch, so a document whose output estimate is badly wrong can still fan out up to 2^4 leaves per root. -> **WS4**
2. **Re-analysis re-runs leaves that deterministically fail.** `reanalyze()` reopens every `failed`, `uncertain` and `budget` leaf, including `split_limit` leaves that will truncate again on the same input, model and prompt. -> **NEEDS HUMAN DECISION** (it changes validated re-analysis semantics).
3. **Model routing via environment:** `claude-sonnet-4-6` traffic.
   - Code never selects 4.6. Before `7210b4e` (2026-10-05 16:27 UTC), synthesis used `ANTHROPIC_SYNTHESIS_MODEL`, then `ANTHROPIC_MODEL`.
   - Since then, an explicitly set `ANTHROPIC_SYNTHESIS_MODEL` still overrides the `claude-sonnet-5-5` default.
   - So the deployed environment must set `ANTHROPIC_SYNTHESIS_MODEL` (or `ANTHROPIC_MODEL`) to `claude-sonnet-4-6`.
   - If it is `ANTHROPIC_MODEL` and `ANTHROPIC_EXTRACTION_MODEL` is unset, **extraction also ran on Sonnet 4.6 at 3x the Haiku price**.
   - -> **WS1**: a guard test, a runtime warning, and `docintel:verify-models` failing on an unapproved model. The env fix itself is a human action.
4. **Legacy small-document path sends the full text 4-5 times** (classification, entities, risks, deadlines, insights, each up to 60K chars).
   - About 5x input amplification for every document under about 14K tokens.
   - -> **NEEDS HUMAN DECISION** (routing change).
5. **Prompt caching is a no-op for extraction.** The cached system block is about 1K tokens, below Haiku 4.5's 4,096-token cacheable minimum. No saving, no harm; the reservation over-estimates input by 25% (cache-write price). -> **NEEDS HUMAN DECISION**.
6. **Tests/scripts reaching the real API: not found.**
   - Every provider call goes through `AnthropicClient`. The only console command that calls the provider is `docintel:verify-models --check-access` (the free Models API, opt-in).
   - `phpunit.xml` uses a fake key, but there is no global stray-request guard: a test without `Http::fake()` would attempt a real request. -> **WS2**
7. **Job-level retries re-calling completed leaves: ruled out by code.** Chunk jobs have `tries = 1` and claim under row locks (`queued`/`pending` only), and completed leaves are skipped (existing tests). Synthesis is checkpoint-guarded. Merge makes no provider call.
8. **Duplicate dispatch: ruled out by code.** Chunk rows use `firstOrCreate` on the identity under the document lock, claims check status under lock, and re-analysis returns 409 while work is active. WS4 adds a test.

## Phase 2: workstreams (implement only low-risk, evidence-supported changes)

| WS | Change | Files | Expected effect | Risk | Tests |
|---|---|---|---|---|---|
| 1 | Approved-model guard: config list of the two approved IDs; runtime warning when a request uses another model; `docintel:verify-models` exits non-zero on one; test that every call purpose resolves to an approved model under default config, and no other model ID is hardcoded in `app/` | `config/document_intelligence.php`, `AnthropicClient.php`, `VerifyAnthropicModels.php`, new test | Surfaces the 4.6 mis-routing immediately (Sonnet 4.6 costs 1.5x Sonnet 5.5; Sonnet 4.6 extraction would cost 3x Haiku) | Low: no request is blocked | new `ModelRoutingGuardTest` |
| 2 | `Http::preventStrayRequests()` in the base `TestCase` | `tests/TestCase.php`, new test | Any real HTTP call in tests fails instead of spending | Low (tests only) | full suite + a stray-request test |
| 3 | Read-only `docintel:ai-usage-report` (Phase 0 tables, metadata only) | new command + test | Lets you measure the real causes in the morning | Low: read-only, no content printed | new test |
| 4 | Per-document split budget: at most `max_split_parents_per_root` x root chunks split parents per pipeline; beyond that, leaves end as `split_limit` (existing partial-coverage semantics) | `IncrementalPipeline.php`, config, tests | Bounds the worst case. The Oct 5 run had 32 split parents on about 5 roots; the default 2 per root allows 10 there. Remaining leaves fail once instead of fanning out | Low-medium: only pathological documents; coverage stays disclosed | new tests |

Not implemented (see the report, "NEEDS HUMAN DECISION"): re-analysis skipping split_limit leaves; salvaging complete records from truncated responses; compact schema and output anchors; prompt caching changes; batch API; kill switch and daily ceilings (they need new env variables or billing changes); the legacy-path routing change; the comparison model metadata label (it feeds the billing fingerprint); the deployed env model values.
