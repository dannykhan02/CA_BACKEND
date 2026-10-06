# DocIntel AI credits: implementation record and release gate

**Date:** 2026-10-06. **Base:** `5f3a7c0` (HEAD at start, working tree clean). **Status:** implemented behind `DOCINTEL_AI_CREDITS_ENABLED` (default `false`); nothing committed, pushed, migrated in production or deployed. The new schedule is **CONDITIONALLY profitable only**: the margins below are *ceiling-based*, not measured, and the production data gathered here does not validate the provisional bands (see Findings 1 and 2).

## 1. What was verified on HEAD before coding

- Scaling audit's SHA `4254b5a` is stale. HEAD already contains queue isolation, the global Anthropic in-flight cap and split budgets (#17). None of the credit work existed (no `ai_credits`, quote or amount columns).
- The audit's billing description was accurate (one document unit debited at Ready, comparisons on a separate counter). Two details it understated:
  - **The incremental path debited at merge time**, once any evidence was merged, *before* synthesis finished. A document that later ended in Needs Review had already been paid for. This is exactly the "charged because some evidence exists" hazard, so under the flag settlement moved to the real Ready transition (`GenerateDocumentSummaryJob`).
  - **Q&A spend is never recorded.** `DocumentQaController` passes no document to `answerDocumentQuestion`, and `recordAiRun` returns early without one. Production has 0 `document_qa` rows. Under the flag Q&A now records its run (first cited document) with `user_id`.

## 2. Findings that change the plan

1. **Production cost data is too thin to set bands.** Read-only, aggregate-only queries (no names, text, prompts, payloads or credentials) over 593 `document_ai_runs` (2026-09-15 to 2026-10-05, 41 documents): 317 runs (53%) have no recorded cost. Only 5 documents have fully known cost:

   | Route | Tokens | Runs | Status | USD | KES @129.79 |
   |---|---:|---:|---|---:|---:|
   | legacy (DOCX) | 8,000 | 10 | Ready | 0.101 | 13 |
   | incremental (PDF) | 15,385 | 26 | Needs Review | 0.453 | 59 |
   | incremental (PDF) | 62,125 | 74 | Needs Review | 1.411 | 183 |
   | incremental (PDF) | 63,393 | 55 | Needs Review | 0.634 | 82 |
   | incremental (PDF) | 77,176 | 75 | Processing | 1.319 | 171 |

   p50 0.63, p90 1.37 USD, **n = 5**. The four incremental documents are incident-era (before the split budget and spend ceiling landed), so they overstate steady-state cost, and none reached Ready. **These are not usable quantiles.** There were 0 comparison runs and 0 Q&A runs, so comparison, Q&A, OCR (1 run, cost unknown) and re-analysis cost are entirely unmeasured. Models seen: Haiku 4.5 (588 runs), Sonnet 4.6 (5); no Sonnet 5.5 run yet.
2. **The provisional 4/10-credit bands cannot be funded on the incremental route.** The pipeline admits spend against *reservations*. Its own conservative synthesis reservation (primary + one fallback + one repair, computed locally) is USD 0.33 for a 1k-token document, 0.37 at 5k, 0.50 at 20k, 0.85 at 60k, 1.05 at 150k. At the provisional USD 0.027 per credit (KES 3.50) a 4-credit cap is 0.108 and a 10-credit cap 0.27, so any incremental-route document (above 14k tokens or 60k characters) cannot even reserve its synthesis inside those caps. The quote therefore moves it up a band or declines it. Legacy-route documents (small) are not affected: the 8k-token example cost USD 0.10 in total. Note the reservation is an upper bound, not realised spend. Either the cost-per-credit ceiling rises, the synthesis reservation becomes less conservative, or the bands change. This needs a product/engineering decision backed by measured Ready-document cost.
3. **Annual plans miss the 70% target** at the same monthly credits (the report command reproduces the audit): Starter 69.1%, Professional 67.1% at the cap.

## 3. Design as built

| Concern | Implementation |
|---|---|
| Flag | `config/ai_credits.php`, `DOCINTEL_AI_CREDITS_ENABLED=false`. Off: byte-for-byte legacy behaviour (all 78 pre-existing billing tests pass unchanged). |
| Quote | `operation_quotes` (uuid): kind (`document`, `ocr`, `reanalysis`, `comparison`, `qa`), resource, `operation_key` unique (`kind:resource:attempt`), `quote_version`, band, `credits`, `funding_bucket`, `provider_cost_cap_usd`, `preflight` (counts and flags only), status `quoted/reserved/settled/released`, `release_reason`, timestamps. The priced columns are immutable (model guard throws on update). A retry or duplicate delivery returns the same quote, so it can never re-price. |
| Classification | `QuoteService`, local only (no provider call): token estimate (`ai_pipeline.tokens` if already counted, else bytes/3), density from `ExtractionCapacity::density()`, type. Bands and thresholds in config (`simple 4 / standard 10 / large 30 / very_large 80`, token limits 6k/30k/90k/250k, dense moves up one band). Above the top band: declined, not priced. A band whose cap cannot pay the minimum completion (including the real synthesis reservation on the incremental route) moves up, or is declined. |
| Provider ceiling | `credits x provider_cost_usd_per_credit` (default 0.027, deterministic, no live FX; FX is only a report parameter). Applied three ways: (a) the incremental document budget `ai_pipeline.budget_usd = min(existing formula, quote cap)`, reusing the existing reservation mechanism; (b) at the single HTTP boundary `AnthropicClient::callWithRetry`, `OperationSpend::assertCallAllowed` refuses a paid call with no reservation (`credits_not_reserved`) or an exhausted cap (`budget_exceeded`), whichever path made the call (legacy four-job path, OCR, visuals, KPI identity, context, comparison, Q&A); (c) every run is stamped with `operation_quote_id`, `user_id` and `comparison_id`. Unknown usage (timeouts) counts at `unknown_usage_cost_usd` (0.05), never zero. A call already in flight can overshoot the cap by at most one response. |
| Reservation | `CreditAccountant::reserve` under the workspace row lock, amount >= 1, stored on `billing_operations` (`quote_id`, `amount_reserved`, `funding_bucket`, `quote_version`). Reserving changes no counter; availability subtracts open reservations. Idempotent per quote. |
| Funding | Monthly AI credits of the active period pay first; saved credits are untouched while a subscription period exists (today's rule) unless `AI_CREDITS_SAVED_FALLBACK=true`. One operation, one bucket (no splitting). Ledger units `subscription_ai_credit` and `saved_ai_credit` keep buckets separate. |
| Settlement | Exactly the quoted amount, once, at the real Ready transition (summary job / insights job). Merged evidence alone never debits. Retries, splits, synthesis fallback, queue restarts and timeouts add nothing. |
| Release | Failed, document removed, expired (24h) reservations release; Needs Review releases by default (`AI_CREDITS_NEEDS_REVIEW_POLICY=settle` makes a partial result a paid state). Settled work is never released; released work is never settled. |
| OCR | Own operation (`kind=ocr`), priced from the rasterised page count before any vision call: 20 credits + 1 per page beyond 20; hard cap 60 pages (declined, no call); provider cap 0.02 USD per page. Admission also requires room for the cheapest analysis band. Settles/releases with the document. |
| Comparison | `kind=comparison`, 12 credits, quote + reserve + cap + settle/release; calls stamped with the comparison id. Non-AI structured comparison stays free. |
| Re-analysis | Explicit full re-analysis (`DocumentReprocessor`): a delivered document is charged once as `reanalysis` at its current band (or fixed price) with its own ceiling added to the committed spend. A never-delivered document is re-admitted as a normal analysis, not charged twice. Summary-only refresh and internal recovery cost 0 credits (still bounded by the settled quote's ceiling). |
| Visual analysis | Included in the document band; capped by the document's quote ceiling (also after Ready). No surcharge. |
| Q&A | `QaGuard` (when enabled): 100 questions/workspace/day, 30/user/hour, USD 2/workspace/day. Customer debit gated off (`AI_CREDITS_QA=0`); when set, quote + reserve + settle per answer. |
| Confirmation | Bands from `large` up wait before any credit is reserved (`ai_pipeline.awaiting_credit_confirmation`, listed in `pendingConfirmations`). `POST /api/documents/{id}/confirm-credits` resumes via `ResumeAfterCreditConfirmationJob` (default queue); credits are reserved when it runs. |
| Subscriptions | New checkouts snapshot `ai_credits` (per interval, so annual can differ). New periods store `ai_credits_allowed`. **A period created before rollout keeps legacy 1-unit accounting** (`mode=legacy`) until it ends; snapshots are never rewritten. Grandfathered Starter periods stay legacy. |
| Free / referral | Trial: 20 saved AI credits through the same system (existing email/IP/fingerprint guard unchanged). Referral: 100 credits (= 10 units x 10). |
| Legacy saved balances | Not rewritten. Saved document units convert **lazily, at spend time**, at `legacy_saved_credits_per_document` = 10 (5 free documents = 50 credits, more than the new 20-credit grant; no customer loses value). Ledger records `ai_convert_out` / `ai_convert_in`. Reversible until a customer spends; irreversible conversion happens only per spent unit. |
| API | `GET /workspace/credits` and `/workspace/billing` gain `ai_credits` (`enabled, mode, monthlyCredits, monthlyCreditsUsed, monthlyCreditsReserved, monthlyCreditsRemaining, monthlyCreditsResetAt, savedCredits, savedCreditsReserved, savedCreditsAvailable, availableCredits, minimumRequiredCredits, pendingConfirmations`). `/billing/plans` gains `ai_credits_enabled`, `free_initial_ai_credits`, per-plan `ai_credits`. Legacy fields keep their meaning. |
| Observability | `Log::info('AI credit operation reserved/settled/released')` with ids, band, quoted/reserved/settled/released credits, bucket, cap, provider cost, `actual_known`, version, status. No content. |
| Report | `php artisan docintel:credit-economics-report [--days --fx --fee --margin --cost-stress --fx-stress --json]`: plan margins at the ceiling and under stress, per kind/band quantiles (p50/p90/p95/p99/max), unknown-run counts, over-cap counts, USD per settled credit, releases, and the pre-rollout cohort. Read-only. |

## 4. Margins (ceiling-based, from the report command; not measured)

FX 129.79, fee 2.9%, ceiling USD 0.027 (KES 3.50) per credit, every credit spent at its cap:

| Plan | Price/month | Credits | Max provider KES | Margin at cap | +25% cost | +25% cost, +10% FX |
|---|---:|---:|---:|---:|---:|---:|
| Starter monthly | 1,500 | 100 | 350 | 73.7% | 67.9% | 65.0% |
| Professional monthly | 3,500 | 250 | 876 | 72.1% | 65.8% | 62.7% |
| Starter annual | 1,250 | 100 | 350 | 69.1% | 62.1% | 58.6% |
| Professional annual | 2,917 | 250 | 876 | 67.1% | 59.5% | 55.8% |

Monthly plans meet 70% at the cap but **not** under the +25% cost / +10% FX stress, as the audit also found. The cap is only a guarantee where it is enforced (it now is, at the HTTP boundary, once the flag is on).

**Annual options (not applied; STOP for product approval):** A keep prices, lower annual monthly credits to about 96 (Starter) / 225 (Professional) for 70%; B raise annual prices to about KES 15,600 / 38,800; C accept 69.1% / 67.1%; D reduce the annual discount (currently 16.7%).

## 5. Release gate (must pass before `DOCINTEL_AI_CREDITS_ENABLED=true`)

1. Production cost quantiles from **Ready** documents with fully known usage, per band, route, OCR and comparison, after the cost-control fixes (today: n = 5, incident-era). Run `docintel:credit-economics-report` after a soak period with the flag off to capture cost per document, then with it on in a test workspace.
2. Resolve Finding 2: pick the cost-per-credit ceiling and/or the synthesis reservation so every band can finish useful work inside its cap at p95 (or accept the 30/80-credit minimum for incremental documents).
3. Decide: annual allowances, Needs Review policy, saved-credit fallback, legacy-period migration at renewal, grandfathered Starter, Q&A pricing, OCR price.
4. Reconcile aggregate provider spend with the Anthropic invoice.
5. Verify: 70% contribution at 100% use at baseline, and still profitable at +25% cost and +10% FX.

## 6. Environment variables (all optional; defaults shown)

Only `DOCINTEL_AI_CREDITS_ENABLED=false` is documented as active in `.env.example`; the rest are commented. **No existing env var was renamed.**
`DOCINTEL_AI_CREDITS_ENABLED`, `DOCINTEL_AI_CREDITS_QUOTE_VERSION=v1`, `AI_CREDITS_SIMPLE|STANDARD|LARGE|VERY_LARGE` (4/10/30/80) and `_MAX_TOKENS` (6000/30000/90000/250000), `AI_CREDITS_DENSE_BAND_SHIFT=1`, `AI_CREDITS_CONFIRM_FROM_BAND=large`, `AI_CREDITS_PROVIDER_USD_PER_CREDIT=0.027`, `AI_CREDITS_FX_KES_PER_USD=129.79`, `AI_CREDITS_PAYMENT_FEE_RATE=0.029`, `AI_CREDITS_MARGIN_TARGET=0.70`, `AI_CREDITS_MIN_COST_BASE_USD`, `AI_CREDITS_MIN_COST_PER_1K_USD`, `AI_CREDITS_UNKNOWN_USAGE_USD=0.05`, `AI_CREDITS_OCR_SURCHARGE=20`, `_OCR_INCLUDED_PAGES=20`, `_OCR_EXTRA_PER_PAGE=1`, `_OCR_MAX_PAGES=60`, `_OCR_USD_PER_PAGE=0.02`, `AI_CREDITS_COMPARISON=12`, `AI_CREDITS_REANALYSIS_PRICING=band`, `_REANALYSIS_FIXED=10`, `AI_CREDITS_QA=0`, `_QA_MAX_PER_DAY=100`, `_QA_MAX_PER_HOUR=30`, `_QA_MAX_USD_PER_DAY=2.0`, `AI_CREDITS_NEEDS_REVIEW_POLICY=release`, `AI_CREDITS_SAVED_FALLBACK=false`, `AI_CREDITS_FREE_TRIAL=20`, `AI_CREDITS_REFERRAL_REWARD=100`, `AI_CREDITS_LEGACY_PER_DOCUMENT=10`, `AI_CREDITS_{STARTER,PROFESSIONAL}_{MONTHLY,ANNUAL}` (100/250).

## 7. Safe Railway rollout (nothing below has been run)

Expand/contract. The migration is additive and **must run before the new code serves traffic**, because even flag-off code reads `billing_operations.amount_reserved`. `bin/start-production.sh` does not migrate.

1. Review and commit (not done here). Do not enable the flag.
2. Back up first: in the Postgres container with the PostgreSQL 18 client tools (AGENTS rule 5), `pg_dump` the `railway` database. Compare nothing from `pulse_*`.
3. `railway status` (confirm project `superb-emotion`, production, linked service). One command at a time: `railway ssh --service CA_BACKEND --environment production -- php artisan migrate:status`, then `... -- php artisan migrate --force` for `2026_10_07_000001_add_ai_credit_accounting`. All changes are nullable/defaulted additions; `down()` exists.
4. Deploy the commit to `CA_BACKEND` and `ca-horizon-worker` together (flag still false). Never run Horizon locally against Railway (AGENTS rule 2).
5. Soak with the flag off; capture cost data with the existing `docintel:ai-usage-report` and the new report.
6. After the release gate and explicit approval: set `DOCINTEL_AI_CREDITS_ENABLED=true` in Railway (a variable change), redeploy web and worker, then new subscriptions snapshot AI credits. Roll back by setting it false: new quotes stop; open AI reservations settle/release normally because they are stored in `billing_operations`.

## 8. Post-deploy validation

- Migration present in `migrate:status`; `GET /api/workspace/credits` returns `ai_credits.enabled=false` and unchanged legacy fields.
- Upload one small document with the flag off: one legacy reserve and one debit; no `operation_quotes` rows.
- With the flag on in a test workspace: `ai_credits` block present; a simple document creates one quote, one reserve ledger row, one debit at Ready, and `document_ai_runs.operation_quote_id` populated; a forced failure releases; `docintel:credit-economics-report` lists the operation with zero `over_cap`.
- Ledger reconciliation: per workspace, `reserve = debit + release + open reservations`; no negative `ai_credits_remaining`.
- Logs show `AI credit operation settled` lines with no content fields.

## 9. Not done / remaining

- **Frontend:** balance banner, plan cards, billing page and confirmation button are done and flag-gated. Not done: a "this analysis will use N credits" line inside the upload, comparison and re-analysis dialogs; checkout/return pages; document action buttons. The upload-time estimate is not possible (the price depends on extracted text), so the quote appears after extraction.
- Annual prices, plan prices, packs, synthesis model, payment logic: unchanged and awaiting approval. Credit packs are not enabled; `grantSaved()` is the future hook.
- Premium/deep mode is not productised.
- `docintel:resume`/recovery behaviour for documents waiting on confirmation is not covered by a dedicated test (admission is idempotent, so a re-dispatch re-enters the same wait).
- Legacy-period subscribers and grandfathered Starter stay on document units; a customer-visible migration at renewal is a product decision.
- Local note: the developer's PG14 had a `CREATE DATABASE` session stuck since 09:52 that blocked every test run; tests were run against a throwaway PG16 on port 54329 with the user's agreement. That session was not touched.

## 10. Verification (2026-10-06)

- Backend `php artisan test` (isolated PG16): **760 tests, 754 passed, 3 skipped, 3 failed**. The 3 failures (`HealthCheckTest` x2: Redis not running locally; `ScanUploadedFileJobCliTest` real clamscan: ClamAV not installed) fail identically on clean HEAD. New: `AiCreditsTest` (47), `WorkspaceAiCreditsConcurrencyTest` (4, forked workers), `CreditEconomicsReportTest` (3). Existing `WorkspaceCreditsTest`, `SubscriptionBillingTest`, `CreditPurchaseTest`, `IncrementalDocumentPipelineTest`, `DocumentIntelligenceFailureTest`, `DocumentAiUsageReportTest`, `QueueTopologyTest` all pass.
- Frontend `vitest run`: 76 tests, 72 passed, 4 failed. The 4 (`referral-signup.test.tsx`, jsdom lacks `IntersectionObserver`) fail identically on clean HEAD. New `ai-credits.test.tsx`: 10 pass. `tsc --noEmit` and ESLint on touched files are clean.
- `git diff --check` clean; Pint applied to the touched PHP files only.
- A first full run failed 32 tests because the concurrency test committed fixtures (`DatabaseTruncation`) under a file name that sorted before `Api/` and `Auth/`. It was renamed to sort with the other concurrency tests and now truncates `users`/`workspaces` in `tearDown`.
