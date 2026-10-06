# Overnight report: AI cost control (2026-10-06)

Branch `chore/ai-cost-control`. Nothing was pushed. No live API or network calls, `.env` was not read, and no migrate, seed, queue or horizon command was run.

**ENV VARIABLE NAMES CHANGED: NO.** No env variable was added, renamed or removed. Model IDs are unchanged.

**Full suite: 657 passed, 0 failed (4,722 assertions).**

## Headline

1. **Sonnet 4.6 traffic is a deployment config problem, not code.**
   - No code path selects `claude-sonnet-4-6`.
   - Before commit `7210b4e` (2026-10-05 16:27 UTC), synthesis used `ANTHROPIC_SYNTHESIS_MODEL`, falling back to `ANTHROPIC_MODEL`.
   - Since then, an explicitly set `ANTHROPIC_SYNTHESIS_MODEL` still overrides the new `claude-sonnet-5-5` default.
   - So the Railway environment almost certainly sets `ANTHROPIC_SYNTHESIS_MODEL` (or `ANTHROPIC_MODEL`) to `claude-sonnet-4-6`.
   - **If `ANTHROPIC_MODEL` is the 4.6 one and `ANTHROPIC_EXTRACTION_MODEL` is unset, extraction also ran on Sonnet 4.6 at 3x the Haiku price.**
   - Fix (human): set `ANTHROPIC_SYNTHESIS_MODEL=claude-sonnet-5-5` and `ANTHROPIC_EXTRACTION_MODEL=claude-haiku-4-5-20251001`, or delete the overrides. Then confirm with `docintel:verify-models`, which now exits 1 on any unapproved model.
2. **The main token burn on Oct 5 was truncated extraction responses** (modeled; confirm with the new report command).
   - Old routing: 14K-token chunks with a 4,096-token output cap.
   - Every `max_tokens` stop billed the full 4,096 output tokens, discarded the partial JSON, and re-sent the text as two halves.
   - sector_report alone: about 46 truncated calls, ~188K output tokens discarded, about 7-8x amplification.
   - The committed capacity-aware routing already removes most of this. Tonight's split budget caps what is left.

## 1. Phase 0 measurement tables

**Real numbers were not obtainable tonight:**
- The local dev database (port 5432) is down, and starting it needs sudo.
- The Oct 3-5 spend happened on production, and this run made no network calls.
- Usage *is* persisted per call (`document_ai_runs`: model, purpose, input/output/cache tokens, `chunk_id`, attempt, status, `failure_class`, latency, cost), so nothing was missing.

The new **`php artisan docintel:ai-usage-report {document?} {--days=7} {--limit=10} {--json}`** prints every Phase 0 table:
- spend by day, model and purpose
- worst documents by amplification
- provider calls by outcome
- extraction leaves by outcome
- wasted spend
- overlap re-sends
- retry spend
- completed leaves billed more than once

It runs in a Postgres `READ ONLY` transaction and prints IDs, counts, tokens and USD only.

**Modeled numbers for the verified case** (from the known sector_report counts):

| | Old routing (Oct 5 run) | Current routing |
|---|---|---|
| Extraction calls | 74 (28 completed, 32 split parents, 14 split_limit) | 4 planned |
| Truncated and discarded calls | 46 | 0 expected; at most 2 splits per root |
| Discarded output tokens | ~188K (46 x 4,096) | ~0 expected |
| Input re-sent by splitting | ~3-4x the document | ~1.02x (overlap only) |
| Amplification | ~7-8x | ~1.7x including Sonnet synthesis |
| Overlap re-send | | 3 boundaries x 400 tokens = ~1.2K (2%) |
| Completed leaves re-called by job retries | 0 (tries=1, claim under lock) | 0 |
| Tests or scripts able to reach the real API | none found; now enforced | none |

## 2. Ranked root causes, with evidence

1. **Truncation, then split, then resend** (largest modeled share; output costs 5x input on Haiku).
   - Evidence: 46 failed calls on one document; output roughly equal to input on Oct 5; old 4,096-token cap against ~8K needed.
   - Mostly fixed by the committed routing. The whole-tree bound was still missing; added in WS4.
2. **Model mis-routing through env** (Sonnet 4.6 costs 1.5x Sonnet 5.5 for synthesis, or 3x Haiku if it also served extraction). Evidence: git history above and the Oct 5 provider chart.
3. **Re-analysis re-runs deterministic failures.** `reanalyze()` reopens `split_limit` leaves, which truncate again on the same input, model and prompt. Evidence: code (`IncrementalPipeline::reanalyze`). Not changed: NEEDS HUMAN DECISION.
4. **Legacy small-document path sends the full text 4-5 times** (classification, entities, risks, deadlines, insights; up to 60K chars each). Not changed: routing change.
5. **Prompt caching is a no-op for extraction.** The cached system block is about 1K tokens, below Haiku 4.5's 4,096-token cacheable minimum (verified in the current docs). No saving and no harm.

**Ruled out by code:** job-level retries re-calling completed leaves (tries=1, status claim under lock, existing tests), duplicate dispatch (idempotent chunk rows, locked claims, a 409 on re-analysis while active), and tests calling the real API (a fake key, plus the new global guard).

## 3. Changes made

| Commit | Change | Expected effect | Risk |
|---|---|---|---|
| `47cae0c` | Plan and Phase 0/1 analysis docs | n/a | none |
| `9428f8d` | `document_intelligence.approved_models` (the two IDs); metadata-only warning when a request uses another model; `docintel:verify-models` lists every task, shows "Approved", and exits 1 on any unapproved model; `ModelRoutingGuardTest` | Makes the 4.6 mis-routing visible immediately. Fixing the env cuts synthesis cost about a third, or about two thirds on extraction if 4.6 served it | Low: requests are not blocked |
| `c220a7f` | `Http::preventStrayRequests()` in the base `TestCase`; `StrayHttpRequestGuardTest` | No test can spend money | Low: the suite passes with it |
| `2531f50` | `docintel:ai-usage-report` (read-only, metadata-only); `DocumentAiUsageReportTest` | Real measurements in the morning | Low |
| `0b35d05` | Per-document split budget `max_split_parents_per_root` = 2, checked under the document lock; beyond it, leaves end as `split_limit` with disclosed partial coverage; `CostGuardrailsTest` | Worst case is now roots + 2 x (2 x roots) extraction calls. The Oct 5 tree had 32 split parents on about 5 roots; it is now capped at 10 | Low-medium: only pathological documents lose coverage, and they report it |

## 4. Guardrails and config keys

- **New:**
  - `document_intelligence.approved_models` = `['claude-haiku-4-5-20251001', 'claude-sonnet-5-5']`
  - `document_intelligence.max_split_parents_per_root` = `2`
- **Already present** (not changed tonight):
  - per-document budget `budget_usd` (base $0.50 + $0.025 per 1K tokens, capped by `DOCINTEL_MAX_DOCUMENT_COST_USD`). It is enforced before every extraction, context and synthesis call, counts in-flight reservations, protects the synthesis and repair hold, and persists across re-analysis.
  - `max_split_depth` (2), `minimum_split_chars` (2000), `attempts` (3), `concurrency` (2)
- **Not added** (NEEDS HUMAN DECISION): token-ratio amplification breaker, kill switch, daily per-organisation or global ceilings.

## 5. Output-token decisions

None adopted (all NEEDS HUMAN DECISION). The current schema returns 20 required fields per record, and an exact quote per evidence item.

## 6. Prompt caching and batch API (checked against the bundled current docs)

- **Caching:** the Haiku 4.5 minimum cacheable prefix is 4,096 tokens, and the extraction system block is about 1K tokens, so the existing `cache_control` marker never caches.
  - Reservations price input at the cache-write rate (1.25x), so they over-reserve by 25%. That is safe.
  - Cache read/write tokens are already recorded per call and priced by `AiPricing`.
  - Caching the document text across calls doesn't apply, because each partition is unique.
- **Batch API:** 50% cheaper, but results arrive asynchronously (up to 24 hours) and would need a new polling and settlement path. NEEDS HUMAN DECISION.

## 7. Duplicate-dispatch and re-analysis guarantees

- `route()` called three times on the same document creates the same chunks and dispatches no extra jobs (new test).
- Running chunk jobs never call the provider twice; a completed leaf is never re-called (existing tests).
- Duplicate merge deliveries are blocked by a document-scoped `WithoutOverlapping` lock on Redis (existing test).
- Re-analysis invalidates downstream checkpoints and reuses completed leaves (existing tests; not changed).

## 8. Billing guarantees

Not modified. Existing tests cover:
- one debit across a merge retry and duplicate merge deliveries
- synthesis transient retries that neither reserve nor spend twice
- billing outage recovery

## 9. Test safety

- Base `TestCase::setUp()` calls `Http::preventStrayRequests()`, so any unfaked HTTP call throws.
- `phpunit.xml` uses a fake API key (existing).
- Verified by `StrayHttpRequestGuardTest` and by the full suite passing with the guard enabled.

## 10. Files changed and tests

- **Code:**
  - `config/document_intelligence.php`
  - `app/Services/AnthropicClient.php` (warning only)
  - `app/Console/Commands/VerifyAnthropicModels.php`
  - `app/Console/Commands/DocumentAiUsageReport.php` (new)
  - `app/Services/AI/Incremental/IncrementalPipeline.php` (split budget)
- **Tests:**
  - `tests/TestCase.php`
  - new: `tests/Unit/StrayHttpRequestGuardTest.php`, `tests/Feature/ModelRoutingGuardTest.php`, `tests/Feature/DocumentAiUsageReportTest.php`, `tests/Feature/CostGuardrailsTest.php`
- **Docs:** `docs/tasks/overnight-plan.md`, `overnight-progress.md`, `overnight-report.md`
- **Left untouched:** your uncommitted `.claude/settings.json` and `docs/tasks/cost-control.md`.

How the brief's required tests map to the suite:

| # | Requirement | Covered by |
|---|---|---|
| 1, 3 | Hard ceiling and synthesis reserve | Existing budget tests in `IncrementalDocumentPipelineTest` and `DocumentRoutingAndMergeScaleTest` |
| 2 | Circuit breaker | Split budget in `CostGuardrailsTest`. The ratio breaker was not adopted |
| 4 | Completed leaf never re-called | Existing redelivery and interruption tests |
| 5, 6 | Split rules and bounds | `DocumentRoutingAndMergeScaleTest`, `CostGuardrailsTest` |
| 7 | Duplicate dispatch | `CostGuardrailsTest` plus the merge duplicate-delivery test |
| 8 | Re-analysis | Existing re-analysis tests |
| 9 | No double billing | Existing billing tests |
| 10 | Usage metadata, no sensitive content | Telemetry and diagnostics tests, `DocumentAiUsageReportTest` |
| 11, 12 | Compact schema and cache settlement | Not adopted |
| 13 | Real HTTP fails a test | `StrayHttpRequestGuardTest` |
| 14 | Named suites | Pass |

**Model actually used per call purpose** (code default; production depends on the three env variables):

| Purpose | Resolution chain | Default |
|---|---|---|
| extraction (incremental chunks, recorded as purpose `entities`), token counting, entities, risks, deadlines, document_type, insights, context_resolution, ocr, chart_vision, kpi_identity, classification, repair | `ANTHROPIC_EXTRACTION_MODEL` -> `ANTHROPIC_MODEL` -> haiku | `claude-haiku-4-5-20251001` |
| document_summary (synthesis, including the legacy summary), summary_repair, document_comparison, document_qa | `ANTHROPIC_SYNTHESIS_MODEL` -> `claude-sonnet-5-5` | `claude-sonnet-5-5` |
| any other task | `ANTHROPIC_MODEL` | none in code today |

**Mislabels found (not fixed):**
- `DocumentComparisonService` stores `services.anthropic.model` (Haiku) as the comparison's `metadata.model`, but the call uses SMART.
- `workspace_ai_configs.model` defaults to `claude-sonnet-4-6` but is never used for selection.

## 11. Test results

| Run | Result |
|---|---|
| Focused new tests | 10/10 |
| IncrementalDocumentPipelineTest | 64/64 |
| PersonalDocumentWorkflowTest | 27/27 |
| PersonalWorkspaceJourneyTest | 4/4 |
| `git diff --check` | clean |
| `pint --dirty` | passed |
| Full suite | 657 passed, 0 failed |

The tests ran on a throwaway Postgres (port 55432) and Redis in the session scratchpad, both stopped afterwards. Locally, `APP_ROUTES_CACHE` was pointed away from the stale `bootstrap/cache/routes-v7.php`; without that, 33 route tests return 404 (a pre-existing local issue).

## 12. Config changes

The two new config keys above; no env changes. **ENV VARIABLE NAMES CHANGED: NO.**

## 13. NEEDS HUMAN DECISION (not implemented)

| Item | Trade-off |
|---|---|
| Fix the Railway model env values | Required to stop Sonnet 4.6 spend. A human action in the Railway dashboard; never paste the values into chat |
| Re-analysis skips `split_limit` leaves | Saves a guaranteed-truncating call per leaf per re-analysis. Changes validated re-analysis semantics; a user could no longer force a retry after a model or prompt fix (unless keyed on prompt/model version) |
| Salvage complete records from truncated JSON | Would recover most of the paid output of truncated calls. Touches evidence validation and partial-response semantics |
| Compact schema / output anchors instead of quotes | Possibly 30-50% fewer output tokens per record. Changes the schema, validation and merger, and needs an eval |
| Prompt caching | Only useful if the cached prefix reaches 4,096 tokens on Haiku 4.5 (it doesn't now). Padding the prompt would raise cost |
| Batch API for extraction | 50% cheaper, but latency of up to 24 hours plus new settlement and idempotency paths |
| Token-ratio amplification breaker | Overlaps with the budget and the split cap. A naive version blocks user re-analysis permanently |
| Kill switch and daily per-organisation/global ceilings | Needs a new env variable (to be useful operationally) and billing-adjacent failure states |
| Legacy path text re-sends | Route small documents through a single direct extraction. Routing change |
| Comparison `metadata.model` label | Metadata feeds the comparison idempotency fingerprint, so changing it could re-bill re-requested comparisons |
| Dry-run / fake provider as the default outside production | Safe only if production is explicitly enabled, which needs a new env variable and a deploy-checklist change |

## 14. Remaining risks and follow-ups

- The modeled numbers must be confirmed with the usage report before tuning further.
- The per-document budget (up to $10 per document via `DOCINTEL_MAX_DOCUMENT_COST_USD`) is generous; consider lowering it.
- Use a **separate low-limit Anthropic key with a spend cap for development**, so local runs and stray scripts cannot drain the production balance. Never share the production key with local `.env` files.
- The per-document budget can still be spent several times over by repeated user re-analysis (by design: re-analysis reopens failures). Consider the re-analysis decision above.

## Morning commands

```bash
git log --oneline -6                      # review the 5 overnight commits
php artisan test --filter='ModelRoutingGuardTest|CostGuardrailsTest|DocumentAiUsageReportTest|StrayHttpRequestGuardTest'
php artisan route:clear                   # local only: stale route cache causes 404s in tests
# Production (read-only; one railway ssh command at a time):
railway ssh --service <backend> --environment production -- php artisan docintel:verify-models
railway ssh --service <backend> --environment production -- php artisan docintel:ai-usage-report --days=7
# Then fix ANTHROPIC_SYNTHESIS_MODEL / ANTHROPIC_MODEL / ANTHROPIC_EXTRACTION_MODEL in the Railway dashboard if verify-models exits 1.
```
