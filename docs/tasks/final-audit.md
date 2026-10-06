# DocIntel final pre-scale audit: findings register and progress

**Started:** 2026-10-06. **Rule set:** no commits, pushes, deploys, railway commands, live Anthropic calls, or production migrations. Resume from the **Progress** section if a session is interrupted.

## Progress (update after each lens)

| Lens / phase | State |
|---|---|
| Phase 0 inventory | done |
| A flag-off / production safety | done |
| B billing | done |
| C cost control | done |
| D security | done |
| E data / performance | done |
| F release / ops | done (docs/tasks/final-audit-ops.md) |
| G frontend | done (partial: see Frontend changes) |
| H FinOps memo | done (docs/tasks/final-audit-finops-memo.md; what-if mode: see I) |
| I tests | done (`FinalAuditInvariantsTest` 26 tests, what-if test, 3 frontend tests) |
| Phase 4 verify | done |

## Phase 0: inventory

| Repo | Branch | Base SHA at start |
|---|---|---|
| Backend | `chore/final-audit` (from `main`) | `9de7f1a` (AI credits #18), tree clean |
| Frontend `../frontend/CA` (separate repo) | `chore/final-audit` (from `feat/ai-credits-frontend`) | `0085bb5`, tree clean |

Last three backend merges to main (`git diff --stat HEAD~3 HEAD`): 64 files, +5182/-140. The substance is: `ProviderGate` + Redis/Memory stores, `QueueTopology`/`QueueInspector`, dispatch tokens on `document_chunks`, `Services/AiCredits/*` (accountant, admission, quote, spend, QA guard), `config/ai_credits.php`, two additive migrations, `confirm-credits` route, and tests (`AiCreditsTest`, `ProviderGateTest`, `QueueTopologyTest`, `QueueBacklogRecoveryTest`, `WorkspaceAiCreditsConcurrencyTest`, ...).

### Environment used for tests
Throwaway PG16 + pgvector on `127.0.0.1:54329` (data dir in scratchpad), throwaway Redis on `54379`. The developer's PG14 on 5432 still has a stuck `CREATE DATABASE` session (see memory note); it was not touched. PG18 (production version) has no pgvector locally, so migrations were exercised on PG16 only (see finding A-3).

### Baseline on clean checkout (before any edit)
- Frontend `npm run typecheck`: clean. `npm run lint`: 0 errors, 5 warnings (existing). `npm run build` (to a scratch outDir, `dist/` untouched): OK.
- Frontend `vitest run`: 100 tests, 98 passed, **2 failed** (`referral-signup.test.tsx` and `users-management.test.tsx`, both 5000 ms timeouts while the backend suite was running in parallel). The previous record says 4 failures for `referral-signup` due to jsdom `IntersectionObserver`; re-checked in Phase 4 on an idle machine.
- Backend `phpunit` on clean checkout: **760 tests, 1 error, 3 skipped**. The one error is `ScanUploadedFileJobCliTest::test_real_clamscan_catches_a_real_eicar_string` (no `clamscan` installed here; environmental). `HealthCheckTest` passes because a throwaway Redis was running (it failed in the previous record only for lack of Redis).

### Unmerged remote branches (read-only; nothing merged)
| Branch | Ahead / behind main | Content | Verdict |
|---|---|---|---|
| `clamav-cli-migration` | +9 / -76 | `CLAMAV_DRIVER=cli` clamscan driver, Dockerfile clamav, CI test, Horizon maxProcesses cuts, lockfile bump | **Superseded.** Main already has the CLI driver (`ScanUploadedFileJob::scanWithClamscan`, `CLAMAV_DRIVER` default `cli`, clamav in the Dockerfile, signature validation in `bin/start-production.sh`). A two-dot diff against main shows main is ahead on all three files. Remaining differences (old Horizon numbers, stale lockfile) would conflict with queue isolation. Do not merge. |
| `horizon-and-deps-cleanup` | +4 / -74 | maxProcesses 10->3 and 5->2, `.gitignore`, lockfile bump | **Superseded / would conflict**: main's `config/horizon.php` was rewritten for three queues; the old numbers no longer map. The lockfile bump is stale: `composer audit` on main still reports 5 advisories (see D). Do not merge. |
| `workspace-insights-kpi-matching` | +2 / -73 | `WorkspaceInsightsController::trends`, route, test | **Already on main** (route `workspace.insights.trends` and `WorkspaceInsightsTrendsTest` exist); branch is stale. No action. |

## Findings register

S1 = money, data loss, security, outage. S2 = customer-visible wrong behaviour. S3 = robustness. S4 = polish. CONFIRMED = traced in code or reproduced by a test; PLAUSIBLE = reasoned only. "Test" names live in `tests/Feature/FinalAuditInvariantsTest.php` (FA) unless stated. No S1 was found.

| id | lens | sev | where | evidence | status | fix / defer |
|---|---|---|---|---|---|---|
| A-1 | 1,2 | S2 | `WorkspaceCreditService::accountForReadyDocument` (gated on the flag), `GenerateDocumentSummaryJob` settle call (gated on the flag) | Flag ON then OFF with an open AI reservation: the legacy path settled the op as one document unit (`EntitlementService::settleDocument` marks it completed and bumps `documents_used`), or debited nothing for a saved-credit op. The AI ledger kept an orphan `ai_reserve`, the quote stayed `reserved`, and `ai_credits_used` never moved. The doc's rollback instructions ("open reservations settle normally") were false. | CONFIRMED | **Fixed.** `hasAiOperation()` now decides, not the flag; summary job settles when an AI op exists. FA `test_flag_turned_off_*` (3), reconciliation test. |
| A-2 | 1,6 | S3 | list of flag-off code touching new columns | `EntitlementService.php` `releaseFailures` reads `amount_reserved`; `summary()` filters `whereNull('amount_reserved')`; `reserveDocument`/`settleComparison`/`releaseComparison` read it; `WorkspaceCreditService::hasAiOperation` queries it (now on every merge and Ready); `SubscriptionService::completePurchase` writes `ai_credits_allowed` (null when the plan has no snapshot); `DocumentAiRun` fillable. | CONFIRMED | Safe because the migration is already applied in production; the migration must precede any rollout elsewhere (checklist). One extra indexed query per Ready/merge with the flag off. |
| A-3 | 6 | S4 | `2026_10_07_000001_add_ai_credit_accounting.php` | Additive; every new column nullable or constant-defaulted (PG11+ fast default, no table rewrite); 2 plain `CREATE INDEX` on `billing_operations` and `document_ai_runs` take a short write lock, trivial at current size (593 runs). `down()` reverses it. | CONFIRMED on PG16 | FA `test_ai_credit_migration_is_additive_defaulted_and_reversible` (information_schema check plus down/up). **Not run on PG 18** (no pgvector build locally): HUMAN CHECK. |
| A-4 | 1,4 | S3 | `DocumentCreditConfirmationController` | Not flag-guarded; a double click dispatched the resume job twice; no rate limit; no balance check (a balance that fell below the quote made the resumed job fail the document later). Authorization was already correct (`reprocess` policy: Personal = uploader only). | CONFIRMED | **Fixed:** 409 when off, atomic confirm (second click is a no-op), `throttle:30,1`, 402 up front. FA tests: other workspace gets 403, double click pushes once, low balance 402, flag off 409, throttle. |
| A-5 | 2,7 | S2 | `AiCreditAdmission::admitReanalysis` | A customer re-analysis of a large document reserved 30 to 80 credits with no price shown and no confirmation (only first analyses had a confirm step). | CONFIRMED | **Fixed:** API path requires `confirm_credits` equal to the quote (409 with the price otherwise); operator/internal callers unchanged; frontend asks. FA tests (2). Reprocess route also gets `throttle:20,1`. |
| A-6 | 1,10 | S3 | `ResumeDocumentIntelligence` | A document waiting for confirmation has no `route`, so recovery never saw it: a lost resume message (after confirming) or a flag turned off while it waited left it `Processing` forever. | CONFIRMED | **Fixed:** `docintel:resume` re-dispatches confirmed ones (and, flag off, all waiting ones) after 10 minutes; admission clears the waiting marker. Never confirms for a customer. FA tests (2). An unconfirmed document still waits indefinitely: **DECISION D-6**. |
| A-7 | 2 | S4 | `ai_pipeline.credit_quote_confirmed` never cleared | A later re-run of a never-delivered document skips the confirmation. Price is the same band, so low impact. | PLAUSIBLE | Defer. |
| B-1 | 2,10 | S2 | `routes/console.php` | Nothing scheduled released expired (24h), failed or orphaned reservations. They released only inside `EntitlementService::summary()` for that workspace, so a dormant workspace kept open reservations (ledger and quote stayed `reserved`, no money effect). | CONFIRMED | **Fixed:** `billing:release-stale-reservations` hourly (reuses the lazy rules). FA tests: sweep releases expired and failed once, idempotent; scheduler contains it, `queue:prune-failed` (30 days, already present) and `docintel:resume`. |
| B-2 | 2 | S2 | `MergeDocumentEvidenceJob.php:273` finalizeMerge | Flag-off legacy debit happens at merge, before synthesis. | CONFIRMED | **DECISION D-1** (not changed). |
| B-3 | 2 | S3 | `releaseFailures` + `settle` | A Needs Review document is released (policy `release`); if it later reaches Ready (summary retry / re-analysis of the same quote), `settle()` finds the op released and does nothing, so a delivered result is free. | PLAUSIBLE | **DECISION D-2** (policy-dependent). |
| B-4 | 2 | S4 | `CreditAccountant::grantSaved` | Bumps the counter before the idempotent ledger insert; safe only because callers are guarded (trial: unique `trial_grants`; referral: row-locked `pending` status). | PLAUSIBLE | Defer; callers verified. |
| B-5 | 2 | ok | reserve/settle/release | Verified: every mutation under the workspace row lock, keyed by quote id, unique ledger references, status guards. Tests: duplicate delivery, retry, mixed run reconciles (reserve = debit + release + open), settle vs release exclusive (FA), concurrency (forked) in existing suite. Subscription rollover keeps the reservation's own period (`usage_period_id`); lazy conversion `ceil` per spent unit; referral 100 credits; trial guard unchanged. | CONFIRMED | none |
| C-1 | 3 | S2 | `DocumentQaController`, `QaGuard` | Q&A spend unrecorded with the flag off (`recordAiRun` needs a document). | CONFIRMED | **Fixed:** the first cited document labels the run always; Q&A no longer uses that document to gate the call; daily provider-spend ceiling (existing config, USD 2/workspace/day) applies flag-off too, counters and debit stay flag-gated. FA tests (2). **DECISION D-4** (the ceiling is new for flag-off customers). |
| C-2 | 3,5 | S3 | `ProviderGate::call/hold` | `release()` in `finally`: Redis down after a paid response threw and discarded the answer and its usage record. | CONFIRMED (test) | **Fixed:** release failures are logged; the lease expires on its own. FA test. |
| C-3 | 3,5 | info | Redis outage | Defined and tested: **fail closed**. `acquire` errors propagate, so no Anthropic request is made without a permit; jobs fail and retry; permits are TTL leases. Q&A (`Cache::add`) and rate limiters also need the cache store: HUMAN CHECK that `CACHE_STORE` is Redis and healthy. | CONFIRMED | FA test (`Http::assertNothingSent`). |
| C-4 | 3 | S3 | `DefersWhenProviderBusy` | A busy deferral never counts attempts and has no cap. Progress is guaranteed because leases expire (lease TTL) and priority waiters are served first, but a persistently saturated gate defers indefinitely. | PLAUSIBLE | Defer; monitor with `queue:status`. |
| C-5 | 3 | S2 | `IncrementalPipeline.php:218-233`, `config/document_intelligence.php:116` | The document budget is `min(formula, DOCINTEL_MAX_DOCUMENT_COST_USD (code default 10), quote cap)`. The pipeline is created in a **worker** job, so the worker's value governs; it is unverified, and an unset worker means USD 10. With the flag on, the quote cap wins for every band (largest 2.16). | CONFIRMED (code) | **DECISION D-3**; HUMAN CHECK. |
| C-6 | 3 | info | every Anthropic call site | All paid requests go through `AnthropicClient::callWithRetry`, which applies `assertCallAllowed` (spend/cap guard) and `ProviderGate::call`. Two other HTTP sites (`models/{id}`, `messages/count_tokens`, lines 94 and 114) go through `ProviderGate` and are free endpoints. No other file contains an Anthropic URL. Job-level `hold()` exists on chunk, visual, summary and comparison jobs. | CONFIRMED | none |
| D-1 | 4 | S2 | `AuthController` / notifications | Default Horizon supervisor runs `tries=1`; `VerificationCodeNotification`, `PasswordResetNotification` and the other transactional mails had no `tries`/`backoff`, so one provider timeout dropped the code in `failed_jobs` (matches the 14-17 Sept failures; root cause in production, probably mail transport or credentials, is unverifiable here). Mail default is `MAIL_MAILER=log` if unset: HUMAN CHECK. Dispatch happens after the signup transaction, so it is not a commit race. | CONFIRMED (code) | **Fixed:** trait `RetriesMail` (3 tries, 30 s / 120 s backoff) on all 7 queued notifications. FA test on `SendQueuedNotifications`. |
| D-2 | 4 | pass | auth throttling | Sign-in: 3 limiters (email+IP, IP, email). Verify code: 5 wrong per 5 min per email, hashed codes. Resend 3/10 min. Forgot 3/10 min. Reset 5/5 min. Responses are generic (no enumeration). `google` sign-in has no throttle (token verified remotely): S4, defer. Expiry-failed verify does not count a hit: S4. | CONFIRMED | none |
| D-3 | 4 | pass | tenant isolation and payloads | New route covered (A-4). Customer APIs (`/workspace/credits`, `/workspace/billing`, `/billing/plans`, documents, intelligence) contain no USD cost, tokens, model names or quote caps; comparison resource hides `metadata.model`. Logs: `AI credit operation *` carry ids and amounts only (existing test). Error messages shown to customers are fixed strings. | CONFIRMED | FA test scans six responses for cost/model/token strings. |
| D-4 | 4 | S3 | `env()` outside config | `HORIZON_AUTHORIZED_EMAILS` read with `env()` in two providers (works on Railway real env vars, breaks with a file-only `.env` + `config:cache`, and fail-closed). `APP_DEMO_MODE` in `AppServiceProvider` and `routes/console.php` (demo only). | CONFIRMED | **Fixed** the first (`config('horizon.authorized_emails')`, same env name; FA test). Demo flag left (S4). |
| D-5 | 4 | S3 | dependencies | `composer audit`: 5 advisories (laravel/framework low, league/commonmark medium+high, league/flysystem low, phpseclib medium); a lockfile bump exists only on the stale `horizon-and-deps-cleanup` branch (commonmark/guzzle). `npm audit`: 11 (8 high) all dev/build chain (`vite`, `tailwindcss`, `esbuild`, `postcss-*`, `micromatch`...), no runtime dependency. | CONFIRMED | Not changed (no dependency churn in a final audit). Schedule a `composer update` of commonmark/flysystem/phpseclib/framework after soak. |
| D-6 | 4 | S4 | `.env.example` | Could not be compared: the rule "never read `.env.*`" covers it and the tool denied the read. Config env names are listed in the ops doc for a human diff. | n/a | HUMAN CHECK |
| E-1 | 6 | pass | indexes | `billing_operations`: unique (kind, resource_id), (workspace_id, status), (quote_id). `operation_quotes`: unique `operation_key`, (workspace_id, status), (kind, resource_id). `document_ai_runs.operation_quote_id` indexed (`spentUsd` per paid call). All new queries are covered. | CONFIRMED | none |
| E-2 | 6 | pass | N+1 | `/workspace/credits` and `/workspace/billing` run a constant ~22 queries (balances evaluated 2-3 times, not per row); does not grow with 12 more reservations. | CONFIRMED | FA test. Cheap future win: memoise `balances()` per request. |
| E-3 | 6 | S4 | locking | Order is always workspace row, then quote, then billing op row (`reserve`, `settle`, `release`, `releaseFailures`, comparison jobs). `QuoteService::issue` locks quotes in its own short transaction without holding the workspace lock. No cycle found. `summary()` takes a workspace write lock on every read (serialises with reserve/settle). | PLAUSIBLE | Defer; if credit polling grows, move the release out of the read path (the new sweep now covers dormant workspaces). |
| E-4 | 6 | pass | failed_jobs | `queue:prune-failed --hours=720` already daily. | CONFIRMED | none |
| F-1 | 5 | info | queue assignment | Every job class in `QueueTopology::ROUTES`; `ResumeAfterCreditConfirmationJob` on default. Queued notifications use the default queue name (`default`) and the `default` supervisor. `config:cache`: only the demo flag still uses `env()` (D-4). | CONFIRMED | none |
| G-1 | 7,8 | S2 | `UploadModal` | With credits on, the dialog still said "1 document analysis per completed file" and "0 available · 1 required". | CONFIRMED | **Fixed** (fetches the balance when opened; legacy wording unchanged when off). |
| G-2 | 8 | S2 | `WorkspaceCreditBalance` confirm button | No pending state (double submit) and a failed confirm was swallowed (unhandled rejection). | CONFIRMED | **Fixed** (disabled while pending, `role="alert"` message). |
| G-3 | 7 | S3 | upload, comparison, re-analysis dialogs | "This analysis will use N credits" is **not available from the API**: the quote exists only for large analyses (`pendingConfirmations`), nothing returns a price for upload (depends on extracted text), comparison (12, config only) or re-analysis (only via the new 409). | CONFIRMED | **Backend gap**, listed below. Re-analysis now shows its price from the 409. |
| G-4 | 11 | S2 (legal) | `LegalPage.tsx` | Privacy still says "Repository documentation identifies a Neon database region in the United States" (stale after the move to Railway: web/worker us-west2, Postgres/Redis sfo). Terms section 7 does not mention AI credits, failed-analysis release or the fixed charge. Third-party AI disclosure (Anthropic, Voyage; processing outside Kenya; retention unverified) is present. Operator name/address/privacy contact are still `[TO BE CONFIRMED]`. | CONFIRMED | **Not edited** (legal text): HUMAN LEGAL REVIEW before enabling credits. |
| G-5 | 8 | S4 | AI-mode refresh button label said "Refresh document balance" | wording only | CONFIRMED | Fixed. |

Not verified by running (needs a browser): 360 px layout, keyboard order, contrast, KES formatting on every page, checkout and payment-return journeys. Static review of the touched components found `aria-live="polite"` on balance, `role="alert"` on shortfall and now on confirm errors, disabled/pending buttons, and `toLocaleString()` for numbers; plural forms handled by `creditsLabel`. Treat the full journey walk as HUMAN CHECK (listed).

## Needs Review: root causes (code) and what is still reachable after the truncation fix

| Cause | Where | After the fix |
|---|---|---|
| Every leaf failed (`no usable evidence`, billing failure message) | `IncrementalPipeline.php:488` | Reachable: provider billing/auth failure, repeated timeouts. |
| Merge produced zero usable evidence (`evidence_total <= omitted`) | `IncrementalPipeline.php:530`, `SynthesisCheckpoint.php:40` | Reachable, mostly via `invalid_evidence` leaves (quote not found verbatim in the source text). |
| Synthesis budget exhausted (`budget_exceeded`, after fallback ladder) | `SynthesisCheckpoint.php:71` | Reachable when the per-document budget is small; the quote cap (flag on) can lower it. Not the truncation re-split loop, which is fixed. |
| Synthesis failed after retries (`markSynthesisFailed`: timeout exhausted, validation, billing) | `GenerateDocumentSummaryJob.php:295` | Reachable (provider timeouts). |
| Summary worker timeout while Processing | `GenerateDocumentSummaryJob.php:341` | Reachable. |
| Merge job failure / final failure | `MergeDocumentEvidenceJob.php:241,312` | Reachable (merge timeout 330 s). |
| Organization OCR/extract failure (Personal gets Failed) | `ExtractDocumentTextJob`, `OcrPageBatchJob` | Reachable, unrelated to AI cost. |
| Legacy four-job path failures | `GenerateInsightsJob.php:122,319` | Reachable for small documents. |
| Truncated response re-split and re-sent (70% of the Oct 5 spend) | extraction | **Fixed** (0 truncated, 0 split in the Oct 6 sample, n = 1). |

## DECISIONS NEEDED

| id | decision | options | recommendation |
|---|---|---|---|
| D-1 | Legacy flag-off incremental path debits at merge, before synthesis. A document that ends Needs Review has already consumed one unit (Starter = KES 75 of allowance value at 1,500/20; Professional = KES 35 at 3,500/100). In the production sample all 4 incremental documents ended Needs Review/Processing; any with merged evidence were charged. | (a) leave as is until credits go live (credits settle only at Ready); (b) refund charged Needs Review documents; (c) move legacy settlement to Ready | (a), plus run the owner query in the ops doc to size exposure; (b) only if the count is material. Not changed here. |
| D-2 | Needs Review policy: `release` (default) vs `settle`; and a Needs Review that later becomes Ready currently stays free. | release / settle / settle-on-late-Ready | Keep `release`; add settle-on-late-Ready only if it is seen in the data. |
| D-3 | Code default `DOCINTEL_MAX_DOCUMENT_COST_USD` is 10 (a worker without the variable allows USD 10 per document). | leave / lower the default to 2 | Lower the default to 2 (safe default). Needs your approval because it changes behaviour wherever the variable is unset. |
| D-4 | Q&A daily provider ceiling (USD 2/workspace/day) is now enforced with credits off. | keep / raise / env-tune | Keep; the existing env `AI_CREDITS_QA_MAX_USD_PER_DAY` tunes it. |
| D-5 | Customer re-analysis of a large document now requires confirming the exact credits. | keep / drop | Keep. |
| D-6 | A large analysis nobody confirms stays `Processing` forever (nothing reserved). | leave / expire to a "needs your confirmation" state after N days / auto-fail after 14 days | Auto-fail after 14 days with a clear message (no charge exists). |
| D-7 | Bands, price per credit, reservation (see FinOps memo). | O1 / O2 / O3 | O2, after soak data. |
| D-8 | Annual plans miss 70% at the same monthly credits (69.1% / 67.1%). | lower annual credits to about 96/225, raise annual price, accept | Lower annual monthly credits (no price change). |
| D-9 | Legal text (privacy region, terms on credits, operator details). | counsel | Required before flag-on. |
| D-10 | Dependency advisories (commonmark, flysystem, phpseclib, framework). | update now / after soak | After soak, one PR. |

## Flag-off safety verdict

With `DOCINTEL_AI_CREDITS_ENABLED=false` the customer-visible behaviour is unchanged: legacy 1-unit reserve at admission, debit at merge/Ready, comparison counter, no quotes (`operation_quotes` stays empty, tested), API returns `ai_credits: {enabled:false}`, trial and referral grants unchanged, spend guard returns early. The deltas from this audit with the flag off are: one extra `billing_operations` lookup per Ready/merge, the hourly release sweep (same rules as the lazy path), Q&A spend now recorded and capped, mail retries, and the confirm/reprocess throttles. The one genuine hazard (A-1, flipping the flag off with reservations open) is fixed and tested. **Verdict: safe to keep OFF in production**, conditional on the HUMAN CHECKS below.

## Go / no-go

- **Current production state (flag off): GO**, with checks H1 to H6. Cost guardrails, queue isolation and gate are in; spend is bounded per document (USD 2 on web, worker unverified).
- **Enabling the flag: NO-GO today.** Criteria: (1) soak data from Ready documents per band (today n = 1 post-fix); (2) decide D-7 so the 10/30/80 bands are reachable for the documents customers actually upload (on the Oct 6 data, a 27k-token document is priced 30 credits while cost 0.355 USD); (3) D-3 and the worker spend ceiling verified; (4) D-8 annual decision; (5) legal D-9; (6) a full browser walk of the journeys at 360 px; (7) migration verified on PG 18; (8) reconcile provider spend with the Anthropic invoice; (9) flip in a test workspace first and run the ledger reconciliation.

## Backend gaps for the frontend (section 9)

1. Upload dialog: needs a pre-upload estimate or a post-extraction quote on the document resource (e.g. `creditQuote: {credits, band, status}` for every quoted document, not only waiting ones).
2. Comparison dialog: expose `ai_credits.prices.comparison` in `/workspace/credits` or `/billing/plans`.
3. Re-analysis: done via the 409 price; a read-only price endpoint would allow showing it before the click.
4. Checkout and payment return pages: nothing credit-specific is returned for a purchase beyond the plan catalog (`ai_credits` per plan is already shown on the plan cards); no change made.

## Frontend changes (`../frontend/CA`, branch `chore/final-audit`, uncommitted)

- `src/components/WorkspaceCreditBalance.tsx`: confirm button pending/disabled state, error alert, AI-mode refresh label.
- `src/components/UploadModal.tsx`: reads the balance when opened; AI-credit wording (chip and insufficient-credits message) only when credits are on.
- `src/documents.ts`: `reprocessDocument(..., confirmCredits?)`.
- `src/pages/DocumentDetailPage.tsx`: handles the 409 price for a large re-analysis (asks, then confirms with the exact credits).
- `tests/ai-credits-audit.test.tsx` (3 tests). `dist.zip` untouched. Build/deploy: `npm run build` to `dist/` served by Netlify (`netlify.toml`, SPA redirect); `dist.zip` is a tracked manual-upload artefact (a zip of the built `dist`, presumably for drag-and-drop deploys). Not changed.

## ENV variables

Added: **none**. Names changed: **NO** (`HORIZON_AUTHORIZED_EMAILS` is now read through `config('horizon.authorized_emails')`, same name). Defaults unchanged; `DOCINTEL_AI_CREDITS_ENABLED` still defaults to false.

## Tests and verification

## Verification (Phase 4, after all edits)

- Backend full suite (throwaway PG16 + Redis, both stopped afterwards): **787 tests, 0 errors, 0 failures, 3 skipped** (baseline 760 with 1 error). New: `FinalAuditInvariantsTest` (26) and one what-if test. The baseline error (`ScanUploadedFileJobCliTest` real clamscan, "Malware scanner unavailable") did not recur on an idle machine; `clamscan` is installed, so it was a load flake (baseline ran beside a frontend test run).
- Frontend: `tsc` clean; ESLint 0 errors, 5 existing warnings; `vitest` **103 passed / 103** (21 files; the two baseline failures were 5 s timeouts under parallel load); `vite build` OK (built to a scratch dir; `dist/` and `dist.zip` untouched).
- `git diff --check` clean in both repos. Pint ran on touched PHP files only. Status shows only intended files (no `.env`, no backups, no `dist.zip` change).
- Every S1/S2 has a fix and a test, or a documented reason: A-1, A-5, B-1, C-1, D-1, G-1, G-2 fixed with tests (G-1/G-2 with the frontend tests); B-2 and G-4 are decisions (not changed); C-5 is a decision plus human check. No S1 found.
- Not done on purpose: no migration run on any DB other than the test database (a direct `migrate` was blocked by the tool policy; reversibility is covered by the in-test `down()`/`up()`), no live Anthropic call, no railway command, no `.env*` read.

## HUMAN CHECKS (could not be verified without Railway access)

- H1: `DOCINTEL_MAX_DOCUMENT_COST_USD` on `ca-horizon-worker` (the pipeline is created there), and the other `DOCINTEL_*`/`ANTHROPIC_*` values on both services.
- H2: `DOCINTEL_AI_CREDITS_ENABLED` really unset or false on both services.
- H3: `MAIL_MAILER` and the mail credentials in production (default is `log`), and why the 14-17 Sept `VerificationCodeNotification`/`PasswordResetNotification` jobs failed (read `failed_jobs.exception` aggregated).
- H4: `CLAMAV_ENABLED` in production (default false means no scanning); the September `ScanUploadedFileJob` failures were the pre-CLI driver.
- H5: `CACHE_STORE` is Redis and healthy (Q&A guard and all rate limiters depend on it); Redis persistence settings for queues.
- H6: migration status in production (`migrate:status` shows both dispatch-token and AI-credit migrations) and PG 18 behaviour of the credit migration.
- H7: `.env.example` versus the env names listed in `final-audit-ops.md` section 2.
- H8: full browser walk (flag off and on) at 360 px: signup/trial, upload, quote and confirm, insufficient credits, ready/needs-review/failed, comparison, re-analysis, billing, plan cards monthly/annual, checkout and return, referral; keyboard order and contrast.
- H9: legal review (decision D-9) and the Anthropic invoice reconciliation.
- H10: the owner commands in the ops doc after the soak.

## Remaining unknowns

Real cost distribution per band (n = 1 post-fix); the worker's spend ceiling; why mail jobs failed in September; production mail/scanner/cache configuration; how many legacy documents were charged and ended Needs Review (D-1 query); PG 18 behaviour; cache-eligible prefix size for extraction (needs `count_tokens`); whether the 50% primary-synthesis share assumed in memo options O1 to O3 holds (the what-if flag lets the owner test it).

