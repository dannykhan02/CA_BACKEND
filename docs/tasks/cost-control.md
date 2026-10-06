# DocIntel: AI cost and token-burn containment

You are a team of senior engineers working on the DocIntel Laravel backend. Work through each lens below in turn (do not spawn many parallel sub-agents; plan usage is limited, so work sequentially and keep context tight):

1. **Staff backend engineer**: pipeline, queues, jobs, retries, checkpoints.
2. **FinOps / cost engineer**: token economics, spend caps, amplification.
3. **LLM / prompt engineer**: output size, schema design, prompt caching, model routing.
4. **Billing and ledger owner**: billing_operations, CreditLedger, idempotency.
5. **QA / test engineer**: deterministic tests, no real network calls.
6. **Security and privacy reviewer**: no secrets or document content in logs.

Record findings per lens. Do not skip a lens.

==================================================
THE PROBLEM (verified, from the provider console)
==================================================

- Credit balance fell from $15.87 to $8.99 within about 12 hours.
- Provider usage chart (last 30 days): about 4.05M input tokens and 1.77M output tokens in total.
- Usage was tiny until about Oct 2, then rose to roughly 0.75M tokens (Oct 3), 1.45M (Oct 4) and 2.3M (Oct 5, UTC), with output tokens roughly equal to input tokens on the worst day. Output tokens cost several times more than input tokens, so this is expensive.
- Claude Code usage chart shows no data, so the spend most likely comes from DocIntel's own provider calls (extraction, repair, synthesis), or from tests or scripts calling the real API.
- Known amplification case: `sector_report.pdf` (about 62,125 tokens) produced 28 completed extraction leaves, 10 budget_exceeded, 14 split_limit, 32 split parents, 193 evidence rows, then a merge worker_timeout at exactly 330 seconds. A single document of this size can burn millions of tokens through recursive splitting, overlap re-sends, retries and re-analysis.

Goal: **bound and minimise token spend per document without losing evidence quality, grounding, billing correctness, or the validated reliability work.**

==================================================
NON-NEGOTIABLES
==================================================

Preserve everything already validated: 599 passing tests (1 skipped, 0 failed), synthesis budget reservation, model-aware pricing, Haiku extraction / Sonnet synthesis routing, actual provider usage settlement, bounded Anthropic retries, partial-document synthesis, re-analysis checkpoint invalidation, billing and CreditLedger idempotency, UTF-8 sanitation, merge coordination guards.

Models stay exactly:
- FAST: `claude-haiku-4-5-20251001`
- SMART: `claude-sonnet-5-5`

Do NOT: add extra API keys, rename `ANTHROPIC_API_KEY` or any env variable, change model IDs, touch frontend or Paystack code, delete migrations or historical evidence, weaken evidence validation, fabricate evidence, bypass budgets, or log API keys, prompts, quotes or document text.

If a routing/merge redesign (context-aware DIRECT / COARSE / DEEP routing and an EvidenceMerger scalability fix) already exists in this repo (check `git log` and the code), build on it and do not redo it. If it does not exist, do NOT implement it here; make every change below compatible with that future work and list it under remaining risks.

NEVER make a live call to the Anthropic API during this task. All tests and experiments must use fakes. Never print or read the contents of `.env` or any key.

==================================================
PHASE 0: MEASURE (read-only, no code changes)
==================================================

Find where provider usage is persisted per call (model, input tokens, output tokens, document, leaf, purpose, attempt, cost). Using the local development database only:

1. Total tokens and estimated cost per document, per model, per purpose (extraction, repair, synthesis), per day for the last 7 days.
2. **Amplification factor** per document = total provider tokens consumed / document tokens. List the worst 10 documents.
3. Count of provider calls per document, split by outcome (completed, max_tokens, context_overflow, budget_exceeded, split_limit, invalid_evidence, timeout, retried).
4. Tokens spent on calls that produced no persisted evidence (wasted spend).
5. Tokens spent on overlap regions that were sent more than once.
6. Tokens spent on retries and on re-analyses of the same document.
7. Whether job-level retries (queue `tries`, worker timeout, restarts) re-called leaves that were already completed.
8. Whether any test, seeder, command or script can reach the real API (search for HTTP calls to the provider, missing fakes, `Http::fake()` gaps).

If usage is not persisted in a form that allows these answers, say so, and treat adding it as the first fix. Present the measurements as a table before moving on.

==================================================
PHASE 1: ROOT CAUSE (by lens)
==================================================

Rank the causes of token burn by measured share, not by guess. Examples to confirm or rule out:

- Recursive splitting resending the same text on every split, with output-limit failures causing most splits.
- Chunk overlap re-sent in every neighbouring chunk.
- Output tokens inflated by verbose JSON, especially repeated exact quotes for every evidence item.
- `max_tokens` too low for the schema, causing truncation then split then resend.
- Retries (HTTP-level and job-level) re-billing the provider for work already done, including client-side timeouts where the provider may still bill generation.
- Re-analysis re-running extraction when nothing upstream changed.
- Duplicate dispatch (user double-click, scheduler overlap, worker restart) running the same document twice.
- Budget checks that are enforced per leaf but not as a hard ceiling across splits, retries and repairs.
- Tests or dev scripts calling the real API with the shared key.
- Repair calls re-sending the whole chunk.

Stop and report if the evidence points to something materially different from this list.

==================================================
PHASE 2: DESIGN (present a plan and wait for my approval)
==================================================

Before changing code, show a plan listing each change, the files affected, the expected token reduction (with the measurement it is based on), the risk, and the tests. Wait for approval. Prefer the smallest changes that cut the largest measured share.

==================================================
PHASE 3: IMPLEMENTATION WORKSTREAMS
==================================================

A. **Hard cost guardrails (defence in depth)**
- A per-document hard ceiling on total tokens and cost across ALL calls (extraction, splits, retries, repair, synthesis), enforced before every provider call and counting in-flight reservations. It must make splitting and retrying stop, not just log.
- A circuit breaker: if a document's amplification factor exceeds a configurable limit, stop extraction, keep valid evidence, record partial coverage, and move on to synthesis only if the synthesis reserve is intact.
- Optional configurable daily token or spend ceiling per organisation and global, with a clear failure state and a kill switch config to disable provider calls.
- All limits come from config with sensible defaults. No magic numbers in code.

B. **Amplification control**
- Split only on capacity failures (max_tokens, context_overflow, justified timeout). Keep existing no-split rules for invalid_evidence, invalid_date, schema failures, budget_exceeded, auth, credits and invalid model.
- Cap total split count and depth per document; enforce a minimum child size.
- Reduce or remove overlap re-sends where evidence deduplication already covers boundaries. Justify the chosen overlap with data.
- Make sure retries and job restarts never re-call a completed leaf: completed work is checkpointed and skipped. Add idempotency keys per leaf attempt where missing.
- Use bounded exponential backoff, and do not retry deterministic failures.

C. **Output-token reduction (output costs the most)**
- Measure output tokens per evidence item. Evaluate a compact response schema (short keys, no redundant fields).
- Evaluate returning source anchors (paragraph or sentence IDs, or offsets) instead of full quotes, with the backend extracting the exact quote itself. Quote validation must stay strict and become deterministic. Reject invalid anchors; never fabricate. If this adds risk, present the trade-off and let me decide.
- Set `max_tokens` from the schema and expected evidence count so valid output is not truncated, and so splits are not caused by an avoidable limit.
- Cap evidence items per request only where existing semantics allow it, and never drop evidence silently.

D. **Provider-side savings (verify against current official docs; do not assume)**
- Evaluate prompt caching for the repeated system prompt, schema and shared document prefix across calls. Check the current minimum cacheable length per model, cache lifetime, and pricing for cache writes and reads. Record cache read and write tokens in usage metadata and settle cost correctly.
- Evaluate the batch API for non-interactive extraction only if latency is acceptable and billing/idempotency stay correct.
- Keep Haiku for extraction and Sonnet for synthesis only.

E. **Re-analysis and duplicate-dispatch protection**
- Re-analysis must reuse valid upstream results when nothing upstream changed, and must invalidate stale downstream checkpoints when it did (preserve existing behaviour).
- Prevent concurrent runs for the same document (lock or unique job). A second dispatch while one is running must be a no-op.
- Optional per-user re-analysis cooldown and a cost estimate shown before a costly re-run, if it fits the existing architecture without frontend changes.

F. **Dev and test safety**
- Fail tests if any real HTTP request is attempted (for example `Http::preventStrayRequests()` in the base test case), and use the existing fake provider everywhere.
- Add a dry-run or fake-provider mode that is the default in local, testing and CI environments, so only an explicit setting enables real calls.
- Recommend (in the report, not by changing secrets) a separate low-limit provider key and spend limit for development.

G. **Observability (metadata only)**
Per call, record: document id, leaf id, purpose, route, model, input tokens, output tokens, cache read/write tokens, attempt, outcome/failure class, latency, estimated cost. Add an artisan command (for example `docintel:ai-usage-report {document?}`) that prints the Phase 0 measurements. Never record API keys, prompts, quotes, evidence content or document text.

==================================================
TESTS REQUIRED
==================================================

Deterministic only (no wall-clock assertions, no real API calls):

1. Per-document hard ceiling stops further calls, splits and retries, and preserves valid evidence.
2. Amplification circuit breaker triggers and records partial coverage.
3. Synthesis reserve is never consumed by extraction, retries or repair.
4. A completed leaf is never re-called after a job retry or worker restart.
5. Capacity failures split; invalid_evidence, schema errors, budget_exceeded, auth and credit failures do not.
6. Split count, depth and minimum child size stay bounded.
7. Duplicate dispatch of the same document is a no-op.
8. Re-analysis reuses valid checkpoints and invalidates stale ones.
9. Retry and merge retry do not double-bill (billing_operations and CreditLedger idempotency).
10. Usage metadata is recorded per call and contains no sensitive content.
11. Compact schema or anchor-based quotes (if adopted): exact-quote validation still rejects mismatches; invalid anchors are rejected.
12. Cache read/write token settlement is correct (if adopted).
13. Any real HTTP call in the test suite fails the test.
14. Existing IncrementalDocumentPipelineTest, PersonalDocumentWorkflowTest and PersonalWorkspaceJourneyTest still pass.

==================================================
VALIDATION ORDER
==================================================

Run focused tests first, then:

php artisan test --filter=IncrementalDocumentPipelineTest
php artisan test --filter=PersonalDocumentWorkflowTest
php artisan test --filter=PersonalWorkspaceJourneyTest
git diff --check
vendor/bin/pint --dirty

Rerun affected tests if Pint changes files, then run `php artisan test`. Do not hide failures. Work on a new git branch and make small, reviewable commits.

==================================================
STOP CONDITIONS
==================================================

Stop and report (do not make broad changes) if:
- Phase 0 cannot be completed because usage data is missing (propose the smallest instrumentation fix first).
- The measured main cause is outside this pipeline (for example, another service using the same key).
- A change would weaken evidence validation, billing idempotency or budget enforcement.
- A change requires renaming an env variable or model ID.

==================================================
REPORT BACK
==================================================

1. Phase 0 measurement tables (amplification, wasted spend, retries, calls by outcome).
2. Ranked root causes with evidence.
3. Each change made, its measured or expected token and cost reduction, and its risk.
4. Guardrails added and their config keys and defaults.
5. Output-token reduction decisions (schema, anchors, max_tokens) and trade-offs.
6. Prompt caching and batch API evaluation results (verified against current docs).
7. Duplicate-dispatch and re-analysis guarantees.
8. Billing guarantees (no double debit on retry, merge retry, synthesis retry).
9. Test safety (no real API calls) and how it is enforced.
10. Exact files changed and tests added or changed.
11. Focused and full test results.
12. Config changes. State explicitly: **ENV VARIABLE NAMES CHANGED: YES/NO**
13. Remaining risks and recommended follow-ups (including a separate dev key with a spend limit).