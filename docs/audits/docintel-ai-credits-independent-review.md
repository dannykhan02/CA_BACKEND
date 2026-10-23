# DocIntel AI credits: independent review

**Date:** 2026-10-23. **Branch:** `fix/ai-credits-independent-review` (from `origin/main` f21e307). **Reviewer stance:** independent auditor first, then a careful fixer. The prior audit (`docs/audits/docintel-notes-credits-templates-audit.md`, 24b7ff2) was treated as a set of hypotheses.

**Hard constraint on evidence.** Nothing was executed: no tests, no migrations, no database or provider contact, no production access. The only commands run were `git`, file reads and searches, `php -l` and `vendor/bin/pint --test` on changed files, plus a throwaway `python3` calculator and text-substitution script that never touched the application (disclosed because the task's allowed-command list names only git, search, read, `php -l` and pint). Every finding is therefore **"traced in code"** or **"written as a test, NOT executed"**. No production fact is claimed.

Companion documents (all committed on this branch):
`docs/audits/ai-credits-lifecycle-trace.md`, `docs/audits/ai-credits-findings-ledger.md` (Parts 1-3), `docs/audits/ai-credits-pricing-analysis.md`.

---

## Definition-of-done answers

| Question | Answer | Confidence |
|---|---|---|
| 1. Can DocIntel incorrectly deduct or retain customer credits? | **Retain: yes, in three narrow, now-fixed ways.** (a) a failed paid reanalysis's hold stayed until the next sweep; (b) an orphaned AI comparison hold (12 credits) was never swept; (c) a one-page scan could not run at all under workload billing (credits released, so no deduction, but the document failed). **Deduct: no wrong or duplicate debit found by tracing.** Debit happens once, for the quoted amount, at publish; every transition is idempotent under a workspace lock and a unique ledger reference. Remaining wrong-charge candidates are policy-level and need your decision (decisions D-2, D-5, D-6). | **Medium-high** for the code paths traced; **low** for production, which I could not inspect. Not demonstrated by any executed test. |
| 2. Is workload-based charging fair and economically sustainable? | **Not yet provable.** It is *safe* at the ceiling for monthly plans (73.7% / 72.1%), **below the 70% target for annual plans** (69.1% / 67.1%), and unvalidated against realised cost (n = 5). It is **not fair as quoted today** in three places: a 2.7x price cliff at about 33k tokens, documents priced as incremental that run on the cheaper legacy route (N1), and Professional customers paying 12-32x more per large document than under the flat legacy plan. | **Medium** (arithmetic from config is exact; realised cost and corpus mix are unknown) |
| 3. What should the new allowances and bands be? | **Do not publish new allowances now.** Adopt the no-price-increase seven-band schedule (A2) after the quote-accuracy fix; choose the credit scale (A1, x10) before anyone holds AI credits if a bigger headline is wanted; raise allowances only after the measured gate (A3: 145 / 340 monthly, 120 / 285 annual per month, conditional). Details in section 7 and `ai-credits-pricing-analysis.md`. | **Verified:** the arithmetic and the invariant (capacity = USD ceiling per plan, not credit label). **Proposal:** every specific number. |

---

## 1. Assessment of the existing audit

**What it got right** (each re-verified in code):
* The lifecycle map (grant, quote, reserve, settle, release) and the "customer pays the immutable quoted band once on a delivered result" contract.
* Dormant by default; fixed quote above cost is policy, not an overcharge.
* Dense-by-file-type, the synthesis-floor band jumps, the thin cost dataset (n = 5, 53% of runs without cost), the report-command shortcomings (C2), the annual margin shortfall, the margin arithmetic (reproduced exactly: 73.7 / 72.1 / 69.1 / 67.1).
* The C7 cause: `releaseDocumentReservation` returns early for a settled document (`EntitlementService.php:110-112`).
* Honest evidence labelling ("not production", "not an observed overcharge").

**Where it was wrong or overstated:** C3 (cap overrun) is incorrect given the hard per-request ceiling and the summed holds (section 3); C7 severity is lower than "High" because the sweep returns the hold shortly after; C5's backend half is already exposed; C1/C2 severities are too high for items that are policy or reporting.

**What it missed:** the quote predicts the wrong route for some documents (N1); orphaned comparison holds are never swept (N2); a one-page OCR quote cannot fund its own request (OCR cap defect); rejected provider responses burn the cap at full ceiling while the run ledger records zero (N3); OCR re-quoted on pre-rollout delivered scans (N4); no cumulative unpaid-spend limit across released attempts; Professional customers' large-document price shock.

**Not re-verified:** the audit's claim of 90 + 61 passing backend tests and the frontend results. I did not run them and do not vouch for them.

---

## 2. Confirmed bugs and root causes

All three were confirmed by tracing code; each now has a regression test (written, not executed) and a separate small fix commit.

### 2.1 Failed paid reanalysis keeps its hold (ledger finding c)

* **Root cause.** `EntitlementService::releaseDocumentReservation` (`app/Services/EntitlementService.php:105`) treated "document has `credit_accounted_at`" as "nothing to release". A paid reanalysis reserves its own `reanalysis` operation on that already-settled document (`AiCreditAdmission.php:116-142`), so when the run fails (`GenerateDocumentSummaryJob.php:503`, `GenerateInsightsJob.php:143, 386`) nothing was returned.
* **Customer effect before the fix.** Held credits stayed out of `availableCredits` until the next balance read or the hourly sweep (the sweep releases Failed/Needs Review documents, `:149`), up to 24 h if the document was in another status. Under `needs_review_policy=settle` the sweep would settle it (decision D-5).
* **Fix (commit a2540fd).** For a settled document, release only open **variable-price `ocr` and `reanalysis`** holds; never the settled `document` debit; skip legacy one-unit holds of settled documents. Idempotent through `CreditAccountant::release` (acts only from `status='reserved'`).

### 2.2 Orphaned AI comparison holds are never released (N2)

* **Root cause.** The sweep (`releaseFailures`, `EntitlementService.php:142-173`, and `ReleaseStaleReservations.php:26`) is restricted to kinds `document`, `ocr`, `reanalysis`. A comparison whose job is lost without calling `failed()` (queue loss, worker kill) keeps its 12 credits reserved. A same-fingerprint retry does not re-dispatch a `queued` comparison (`DocumentComparisonService.php:96-99` acts only when new or `failed`), so the customer cannot free it either.
* **Customer effect before the fix.** 12 credits unavailable indefinitely; ledger `reserve` without matching `debit` or `release`.
* **Fix (commit fba5306).** Variable-price comparison holds older than `billing.pipeline_hours` (24 h) are released with reason `reservation_expired`, in both the lazy sweep and the hourly command, using an **age-only** test (a comparison id is not a document id). Legacy comparison counters are untouched. `credits:audit-settlements` now also lists long-held comparison holds. **Not fixed:** the comparison row itself stays `queued`/`processing` after its hold is released; a delayed job then re-reserves (a new quote) or fails and marks it failed.

### 2.3 A one-page OCR quote cannot fund its own vision request

* **Root cause.** `QuoteService::classifyOcr` (`:119-129`) caps provider spend at `pages x USD 0.02`. `OperationSpend::reserveAttempt` (`:105-128`) refuses a call whose **hard ceiling** exceeds the remaining cap. For one vision request on the default extraction model (Haiku 4.5 at USD 1/5 per million, `max_tokens` 4096, 6,000-token image reserve, `ProviderRequestAdmission.php:97-117`) the ceiling is about `7,612 x 1 + 4,096 x 5 = 28,092` micro-USD, above the 20,000 cap. The first OCR call of any one-page scan throws `budget_exceeded` before dispatch. Document bands have a minimum-completion check (`minCompletionCostUsd`); OCR had none. The existing OCR attribution test uses three pages (cap 0.06), which hides it.
* **Customer effect before the fix.** Under workload billing a one-page scan or a JPG/PNG upload fails (credits are released, so no deduction, but the document fails and no OCR is performed). **Evidence limit:** depends on the production extraction model. If production uses Haiku 5.5 (USD 0.10/0.50) the ceiling is about USD 0.0028 and the defect does not occur; the model in production was not checked.
* **Fix (commit 94169fd).** New `ai_credits.ocr.min_cap_usd` (default `0.05`, env `AI_CREDITS_OCR_MIN_CAP_USD`) as a floor on the OCR **provider cap only**: `cap = max(pages x 0.02, 0.05)`. Credits charged are unchanged; three or more pages keep `pages x 0.02`. It is a new provider-cap default and needs your acceptance (decision D-14).

---

## 3. Incorrect or overstated findings in the prior audit

| Audit item | Claim | Verdict and reason |
|---|---|---|
| C3 | `finishAttempt` accepts actual > reserved, so the cap can be exceeded, "larger under concurrent in-flight responses" | **Overstated / incorrect.** The reservation is a hard ceiling: input bounded by serialized bytes (1 byte at least 1 token) plus full `max_tokens` at the highest applicable rate (`ProviderRequestAdmission.php:97-117`, `AiPricing::maximum` `:34-44`). `reserveAttempt` sums every `reserved`/`dispatched`/`settled`/`uncertain` attempt under the quote lock (`OperationSpend.php:105-128`, `spentUsd` `:97-100`), so concurrent in-flight calls are counted **before** dispatch. Actual exceeds the reservation only if the configured price table or the byte bound is wrong; neither was verified. |
| C7 | High | **Defect confirmed; impact lower.** See 2.1. |
| C5 | Saved credits can look spendable; expose availability | **Backend half already done:** `savedCreditsReserved` and `savedCreditsAvailable` are returned (`EntitlementService.php:213`). Frontend half unverified (out of scope). |
| C1 | High | **Re-rated.** Dense-by-type is policy; reservation-driven jumps are calibration; the real defect (route prediction) is separate (N1). Neither is a wrong debit. |
| C2 | High | **Re-rated Low.** Reporting only; no billing effect. |
| Executive summary | "At full redemption any 50% allowance increase drives margin below 70%" | **True at the ceiling, but incomplete:** the ceiling assumes every credit spent at its cap. Under stated stress inputs larger allowances are possible (pricing analysis A3). The conclusion "do not publish numbers yet" stands because those inputs are unmeasured. |

---

## 4. Code changes and regression tests

| Commit | Change |
|---|---|
| `d4b7e21`, `52db830`, `f61273b` | docs: lifecycle trace, findings ledger, charging-safety checklist |
| `5b4f51f` | tests for the reanalysis release and comparison sweep (written first) |
| `a2540fd` | fix: failed paid reanalysis hold is returned immediately |
| `fba5306` | fix: orphaned AI comparison holds expire |
| `7d7cdc0` | fix: settlement audit lists only variable-price comparison holds |
| `aca71d0` | tests for the OCR cap (written first) |
| `94169fd` | fix: OCR provider-cap floor |
| `9702655` | docs: pricing analysis and recommendations (separate from the fixes) |

Files changed in code: `app/Services/EntitlementService.php`, `app/Services/AiCredits/QuoteService.php`, `app/Console/Commands/ReleaseStaleReservations.php`, `app/Console/Commands/AuditCreditSettlements.php`, `config/ai_credits.php`; docs: `docs/billing-credits.md`; tests: `tests/Feature/AiCreditsIndependentReviewTest.php`. `php -l` and `pint --test` pass on all changed PHP files. **No migration was needed or written.** No price, grant, band, allowance or flag default changed. API changes: none.

### Tests written but NOT executed

| Test (`tests/Feature/AiCreditsIndependentReviewTest.php`) | Expected result | Because | On the pre-fix code |
|---|---|---|---|
| `test_a_failed_paid_reanalysis_releases_its_hold_immediately_and_keeps_the_original_charge` | passes | settled document, open `reanalysis` hold: release returns true, available 80 -> 90, one release row of 10, document debit of 10 intact, second call false and no new rows | **fails** at the first `assertTrue` (early return) |
| `test_releasing_a_settled_document_with_no_open_hold_changes_nothing` | passes | nothing held: returns false, no ledger release, counter 90 | passes (guard that the original rule is intact) |
| `test_an_orphaned_comparison_hold_expires_like_every_other_hold` | passes | fresh hold untouched; after 25 h it is released with `reservation_expired`, quote `released`, available 88 -> 100, one release row of 12, no debit, rerun adds nothing | **fails**: comparison kind is not swept |
| `test_the_hourly_command_releases_an_expired_comparison_hold_and_spares_a_fresh_one` | passes | stale one released, fresh one still reserved, available 88 | **fails**: command ignores comparisons |
| `test_a_one_page_scan_can_fund_its_own_vision_request` | passes | cap 0.05 admits a 28,092 micro-USD ceiling; one run row, spent 0.0015 USD, credits 20 | **fails** with `AiProcessingException('budget_exceeded')` |
| `test_the_ocr_provider_cap_floor_leaves_longer_scans_and_all_prices_unchanged` | passes | cap(1) > 0.02; cap(5) = 0.10; cap(60) = 1.20; credits 20/20/25/60 | **fails** at `assertGreaterThan` for one page |

**Watch in CI.** The six above, plus existing suites that touch the changed code: `AiCreditsTest` (notably `test_ocr_is_priced_from_the_page_count_and_hard_capped`, `test_ocr_and_comparison_and_analysis_spend_are_attributed_to_their_own_operation`, `test_failed_document_releases_its_credits_once`, `test_needs_review_releases_by_default_and_settles_only_under_an_explicit_policy`, `test_expired_reservation_releases_unless_the_work_was_recovered_into_ready`, `test_explicit_reanalysis_is_charged_once_and_internal_recovery_is_free`), `CreditChargeOnPublishTest` (`test_release_is_idempotent_and_never_releases_a_settled_document`, `test_a_legacy_settled_document_is_never_recharged_and_release_is_a_noop`, `test_audit_*`), `FinalAuditInvariantsTest` (the sweep test with expired / failed / live holds, expected ledger release of 20), `WorkspaceAiCreditsConcurrencyTest`, `ProviderSpendReservationTest`, `SubscriptionBillingTest`, `WorkspaceCreditsTest`, `CreditEconomicsReportTest`.

---

## 5. Remaining billing risks

| ID | Risk | Direction | Needs |
|---|---|---|---|
| N1 | Quote predicts incremental from `bytes/3`; runtime routes on the provider count; documents of 42k-60k characters can be priced 30+ and run the legacy route | over-charge | decision D-2 (lowers charges) |
| N3 | Provider-rejected response: run row zero, attempt held at full ceiling, burns the cap, can fail documents under 429/5xx storms | internal cap, no debit | confirm the provider does not bill rejected requests (ASSUMPTION), then settle rejected attempts at 0 |
| N4 | OCR admission ignores `credit_accounted_at`: pre-rollout delivered scans reprocessed get a new OCR charge (20+ credits) with no confirmation; post-rollout ones re-OCR free | inconsistent | decision D-6 |
| N5 | Reprocess pricing differs by route and status (incremental Ready/Needs Review charged; Failed and normal-route free) | inconsistent | decision D-7 |
| N6 | A refused or too-large quote stays `quoted` and is reused with its original band | stale price | low |
| N7 | At flag-on, legacy 1-unit holds are invisible to `saved_reserved`; saved balance can be over-committed by up to 10 credits per legacy hold; clamped debit is ledgered in full | under-collect, ledger overstates | flag-flip checklist |
| N8 | A free re-run of a delivered document is bounded by the settled quote's remaining cap and can stop with `budget_exceeded` | functional | measure |
| R-1 | **No cumulative unpaid-spend limit per document across released attempts**: each retry after a release gets a new quote with a new cap | unbounded provider cost | decision D-13 |
| R-2 | **Band caps versus per-request ceilings are proven only for OCR now.** The 4-credit cap (USD 0.108) must also fund the largest single request of a legacy-route document (the summary call on the synthesis model has a ceiling of the same order). I could not compute it without running the code, so it is unproven for bands 4 and 10 | functional | run the Ready-cohort; add a quote-time minimum per band |
| R-3 | `needs_review_policy=settle` would charge a failed reanalysis (document is Needs Review but the earlier revision is unchanged) | wrong debit if enabled | decision D-5 |
| R-4 | With revisions off, Ready precedes required stages: a later stage failure follows the charge (`docs/billing-credits.md:55-61`) | charge before delivery | keep revisions on |
| R-5 | A comparison whose hold was swept stays `queued`/`processing` in the UI | UX | follow-up |
| R-6 | Synthesis floors and model rates may be stale; thresholds derived from them move | calibration | recompute with `--what-if` on the current model |
| R-7 | Frontend contract and display not reviewed (separate repo) | unknown | frontend review |
| R-8 | Production flags, plans and balances unknown | unknown | production-read-only check by the owner |

---

## 6. Cost-data quality

* **Available:** five fully known documents, one Ready (USD 0.101 on a legacy DOCX at 8,000 tokens), four incident-era PDFs at USD 0.453-1.411 (not steady state, none Ready). 593 runs on 41 documents; 53% of runs without cost (`docs/tasks/ai-credits-implementation.md:12-25`).
* **Not available:** any Ready-document percentile, OCR, comparison, Q&A or reanalysis cost, invoice reconciliation, band x route x dense cohorts.
* **Why the report command cannot supply it today** (verified): it reads only `document_ai_runs` and not `provider_call_attempts`; includes unfinished and released quotes; omits p75; groups null `document_id` rows; ignores unknown-cost quotes (`CreditEconomicsReport.php:135-168`). Per-attempt holds and run rows are two cost records that need not agree (N3).
* **Every percentile or cost in these documents is either a config ceiling (exact arithmetic), one of the five cited points, or labelled ASSUMPTION.** I did not invent any.

---

## 7. Recommended bands and allowances (proposals, not facts)

1. **Do not change numbers now.** Evidence is insufficient.
2. **Quote accuracy first** (lowers charges, needs your approval): predict the route from the signal that routes; recompute the synthesis floors on the current model.
3. **Bands (A2):** 4 / 10 / **25** / 30 / **50** / 80 / **100** credits with token bounds about 6k / 14k / 23k / 32k / 77k / 175k / 241k. No document gets dearer; some get cheaper; some currently declined are accepted. Token bounds are ASSUMPTION until floors are recomputed.
4. **Headline:** if a bigger number is wanted, scale all credit quantities x10 and the per-credit ceiling by 1/10 (A1), **before** anyone holds AI credits. It creates no value.
5. **Allowances:** Starter 100, Professional 250 stay until measured. Conditional larger schedule (A3): 145 / 340 monthly, 120 / 285 annual per month, only when measured utilisation `u <= 0.65` and failure share `<= 0.15`, with the unpaid-retry limit in place. Annual parity at 96 / 225 if annual credits must meet 70% at the ceiling.
6. **Components:** OCR `2 + 1 per page`; free grant 40; reanalysis fixed 10 or 50% of band; density from measured content, not file type.

## 8. Scenarios and margin math

Full tables and formulas are in `docs/audits/ai-credits-pricing-analysis.md`. Key results (config constants exact; scenario inputs ASSUMPTION):

* Margin at ceiling `= 1 - 0.029 - A x 0.027 x 129.79 / R`: Starter monthly 73.7%, Professional monthly 72.1%, Starter annual 69.1%, Professional annual 67.1%.
* Largest allowance meeting 70% at the ceiling: 116 / 271 monthly, 97 / 226 annual-per-month.
* Capacity depends on the USD ceiling per plan-month (Starter USD 2.70, Professional USD 6.75), not the credit label.
* Stress scenario (`u=0.65, r=1, phi=0.15, psi=0.75`): margins 78.8 / 77.5 / 75.2 / 73.6%; with one failed attempt at cap per success: 50.4 / 47.0 / 41.0 / 37.0%.
* Per document, Professional customers pay KES 140 / 420 / 1,120 for 10 / 30 / 80 credits against KES 35 under the legacy flat plan.

## 9. Feature-flag rollout and rollback

* **Today:** `DOCINTEL_AI_CREDITS_ENABLED=false` by default; flag off is the legacy "one credit per published document" policy. Production value not inspected.
* **Rollout order** (nothing run): (1) merge and deploy this PR with the flag off, so the sweep and the OCR cap floor are live but inert for credits; (2) decide D-1..D-15; (3) fix N1 and recompute floors; (4) choose the credit scale and `quote_version` before enablement; (5) collect Ready-cohort cost data with the flag on in a test workspace, reconcile to the invoice; (6) set a cumulative unpaid-spend limit; (7) enable for a named cohort, not globally. Re-run `php artisan credits:audit-settlements` after each step and compare per-workspace `reserve = debit + release + open` (N7 at the flip: verify no workspace has legacy holds).
* **Rollback:** set the flag to `false` and redeploy web and worker. New quotes stop. Open AI reservations remain in `billing_operations` and still settle or release (`WorkspaceCreditService::accountForReadyDocument` is not gated on the flag, `:151`), so rollback does not strand holds. Monthly periods created with `ai_credits_allowed` keep their snapshot; saved AI credits remain stored and are shown only when the flag is on. No migration is required for this PR; the earlier additive migration (`2026_10_07_000001_add_ai_credit_accounting`) is a precondition for any enablement and was not touched.

## 10. Production-readiness decision

* **This PR** (three small fixes, flag-off behaviour unchanged): **ready to merge after CI is green.** The tests were not executed here, so CI is the first execution; if any of the six new tests or the watch list fails, treat that as a finding.
* **Enabling workload billing:** **not ready.** Blockers: no measured cost evidence; N1 (over-charge for some documents); R-1 (unbounded unpaid retries); R-2 (band caps versus request ceilings unproven for bands 4 and 10); annual margin below target; Professional price-shock for large documents; unreviewed frontend contract; undecided policy items. None of these needs new infrastructure, but all need decisions or data.

---

## Frontend contract

Every field below was read from the backend. Nothing in the frontend repo was inspected.

**Meaning of the five states** (all in credits, per workspace):

| Term | Meaning | Changes a counter? |
|---|---|---|
| **reserved** | credits held for an open operation; `billing_operations.status='reserved'`, quote `reserved` | no; availability subtracts it |
| **available** | credits usable right now for a new operation; `allowed - used - reserved` for the monthly bucket, `saved - savedReserved` for saved credits | derived |
| **used** | `subscription_usage_periods.ai_credits_used`, or the drop in saved credits | yes, at settlement |
| **settled** | the quoted amount was debited once on delivery; quote `settled`, ledger `debit` | yes |
| **released** | the hold was returned, no debit; quote `released`, ledger `release` | no |

**`GET /api/workspace/credits` and `GET /api/workspace/billing`** keep all legacy fields (`documents_remaining`, `documents_reserved`, `saved_credits`, `usage`, `subscription`, `paid_access`, and so on) with their legacy meaning, and add `ai_credits`:

| Field | Type | Meaning |
|---|---|---|
| `enabled` | bool | workload billing active; when false this is the only key |
| `mode` | `"credits"` or `"legacy"` | `legacy` = a pre-rollout paid period still on one-document-unit accounting |
| `monthlyCredits` | int or null | allowance of the current period; null when there is no AI-credit period |
| `monthlyCreditsUsed` | int or null | settled this period |
| `monthlyCreditsReserved` | int or null | held by open operations this period |
| `monthlyCreditsRemaining` | int or null | `max(0, allowed - used - reserved)` |
| `monthlyCreditsResetAt` | datetime or null | period end |
| `savedCredits` | int | saved AI credits plus legacy document units x 10 |
| `savedCreditsReserved` | int | held against saved credits |
| `savedCreditsAvailable` | int | `max(0, saved - reserved)` |
| `availableCredits` | int | what admission uses: the monthly remaining while a period exists (saved only if `saved_fallback_during_subscription`), otherwise saved available |
| `minimumRequiredCredits` | int | cheapest band; below this an upload is refused with 402 |
| `pendingConfirmations[]` | `{documentId, credits, band}` | large analyses waiting for the customer's confirmation, nothing reserved yet |

`GET /api/billing/plans` adds `ai_credits_enabled`, `free_initial_ai_credits`, and per plan `ai_credits {monthly, annual}`.

**Operations the UI may call:** `POST /api/documents/{id}/confirm-credits` returns 202 `{data:{credits}}`; 402 if the balance no longer covers it; 409 if not waiting; a double click is a no-op. `POST /api/documents/{id}/reprocess` accepts `confirm_credits` (0 = none yet); a large paid reanalysis answers 409 `{data:{confirmation_required:true, credits, band}}` until `confirm_credits` equals the quote; 402 insufficient; 422 declined.

**Messages the UI may see on a Failed document:** "This document is too large to analyse with AI credits.", "This document cannot be analysed within its credit allowance.", "Insufficient credits: N available, M required.", OCR page-limit and "No pages could be read from this scan." (`AiCreditAdmission.php:32-34, 81-84, 89`).

**Guarantees that hold:** the shown quote is the quote that is reserved (immutable); a retry never re-prices; the displayed `availableCredits` is the value admission checks. **Not guaranteed:** that `savedCredits` is spendable while a subscription is active (`availableCredits` is the spendable figure); that a quote is current after a re-extraction (N6).

## Decisions needed from the product owner

| ID | Decision | Notes |
|---|---|---|
| D-1 | Whether and when to enable workload billing, and the transition for Professional customers | 12-32x per large document vs the flat plan |
| D-2 | Approve fixing route prediction (N1) and recomputing floors | lowers some charges; code only after approval |
| D-3 | Dense policy: file type versus measured content | ledger finding a |
| D-4 | Confirm fixed quote settlement (no usage rebate) | recommended: keep |
| D-5 | Confirm `needs_review_policy=release`, and that a failed reanalysis is never settled | `settle` would charge it |
| D-6 | OCR pricing (`2 + 1/page`), confirmation step, and whether reprocessing a pre-rollout delivered scan is charged (N4) | |
| D-7 | Reanalysis price: band, fixed 10, or 50%; and consistency across routes/status (N5) | |
| D-8 | Credit scale (x1 or x10) and a new `quote_version` before enablement | free only while no one holds AI credits |
| D-9 | Annual allowance: 96 / 225, raise annual price, reduce the discount, or accept 69.1 / 67.1 | |
| D-10 | Free grant: 20 or 40 | KES 70 versus KES 140 provider ceiling per signup |
| D-11 | Allow saved credits during a subscription (`saved_fallback_during_subscription`) | default off |
| D-12 | Legacy periods and grandfathered Starter: when to migrate | |
| D-13 | Cumulative unpaid-spend limit per document; and confirm the provider does not bill rejected requests (N3) | |
| D-14 | Accept the new OCR provider-cap floor default `0.05` | new config, credits unchanged |
| D-15 | Q&A pricing and cap levels | currently free with abuse caps |
