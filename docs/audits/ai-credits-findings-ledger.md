# AI credits findings ledger

**Date:** 2026-10-23. **Companion to:** `docs/audits/ai-credits-lifecycle-trace.md` (same commit base). **Prior audit under test:** `docs/audits/docintel-notes-credits-templates-audit.md` (commit 24b7ff2, treated as a set of hypotheses).

**Evidence levels used here.** *Traced in code* = I followed the call path and cite file:line; nothing was run. *Demonstrated by test (not executed)* = a regression test was written for CI; its expected result is reasoned, not observed. Nothing in this review was executed (task rule 1), so no finding is "demonstrated" by a run.

**Regimes.** *Workload billing* = `DOCINTEL_AI_CREDITS_ENABLED=true` (default false). *Legacy billing* = one credit per published document (`docs/billing-credits.md`). Unless a row says "both", it affects workload billing only.

**Labels.** Every number marked ASSUMPTION is an input I could not verify in the repo. No production cost, percentile or price was invented.

---

## Part 1 - The six reported findings

| # | Reported finding | Verdict | Evidence level |
|---|---|---|---|
| a | Density-based classification inflates charges | **INTENDED POLICY with a PRICING CONCERN** (blunt proxy). Not a wrong debit. | traced in code |
| b | Conservative synthesis reservations push documents into pricier bands | **CORRECT in mechanism; PRICING CONCERN; plus a related DEFECT the audit missed** (quote predicts a different route than the one that runs) | traced in code |
| c | Paid reanalysis has an immediate-release gap on failure | **DEFECT, but OVERSTATED in customer impact** (the sweep returns the hold shortly after) | traced in code; regression test written, not executed |
| d | A fixed accepted quote settles even when actual provider cost is lower | **INTENDED POLICY / PRICING CONCERN**. Not an overcharge by contract. | traced in code |
| e | Historical cost dataset is incomplete | **CORRECT** (data limit, not a bug); report-command shortcomings also confirmed | traced in code + figures from `docs/tasks/ai-credits-implementation.md` |
| f | Workload billing is disabled by default | **CORRECT** and intended; production value unverified | traced in code |

### a. Density-based classification inflates charges

* **Code.** `ExtractionCapacity::density` `app/Services/AI/Incremental/ExtractionCapacity.php:79-102`: `dense = spreadsheet(type XLS/XLSX/CSV) OR numericRatio >= 0.18 OR tabularLineRatio >= 0.30`. `QuoteService::classify` `app/Services/AiCredits/QuoteService.php:64-66` moves a dense document up `dense_band_shift` (1) band. A test pins the behaviour: `tests/Feature/AiCreditsTest.php:125-131` (`test_dense_spreadsheet_moves_up_a_band`).
* **Customer credits.** A 1k-token sheet is quoted 10 credits instead of 4; a 20k-token one 30 instead of 10 (arithmetic from config: bands 4/10/30/80). The charge is the quoted band, once, on delivery. Nothing is debited twice or without delivery.
* **Regimes.** Workload billing only. Legacy billing has no density concept.
* **Is it wrong?** The rationale is real: dense data yields 1.5x the records per 1k tokens (`config/document_intelligence.php:53,58`, 4 vs 6 records) and therefore more output tokens. What is blunt is using the **file extension alone** for spreadsheets (`ExtractionCapacity.php:96-99`): a nearly empty sheet and a 40-column ledger both shift. Content-based density (numeric ratio, tabular ratio) for non-spreadsheets is a sound signal.
* **Calibration versus wrong debit.** This is calibration. The audit's "High" severity (C1) is too high as a billing-correctness item; it is a pricing-fairness item.
* **Smallest safe correction.** None in code. Changing which documents shift would change what customers are charged (task rule 8). Recommendation in the pricing document: for spreadsheets use measured ratios plus an actual-cell-count threshold rather than the extension.

### b. Conservative synthesis reservations push documents into pricier bands

* **Code.** `QuoteService::documentSignals` `:100-111` adds the sum of `synthesis_reserved_usd + synthesis_degraded_reserved_usd + repair_reserved_usd` (`AnthropicClient::synthesisReservation` `app/Services/AnthropicClient.php:1281-1316`) as `synthesisFloorUsd` for a document **predicted** incremental. `classify` `:68-76` moves up while `credits * 0.027 < max(0.05, floor) + tokens/1000 * 0.006`. Caps: 4 credits = USD 0.108, 10 = 0.27, 30 = 0.81, 80 = 2.16. Any floor above about 0.2 therefore skips the 4 and 10 bands; floors of the order the implementation record cites (0.33 at 1k up to 1.05 at 150k, `docs/tasks/ai-credits-implementation.md:25`) force **at least 30 credits** and above about 33k tokens force 80 (arithmetic from the cited floors; ASSUMPTION that the floors are still current, see below).
* **Customer credits.** Every predicted-incremental document costs 30 or 80 credits and needs explicit confirmation (`confirm_from_band=large`, `AiCreditAdmission.php:36-41`). On a Starter allowance (100 credits) one 30-credit document is 30%; one 80-credit document is 80%.
* **What the reservation is.** An upper bound for primary + fallback + repair, not realised cost. The cap is real, though: `ai_pipeline.budget_usd = min(existing formula, quote cap)` (`IncrementalPipeline.php:285-287`, assignment at `:286`), so a lower band would also lower what the pipeline may spend.
* **New defect found while tracing: the quote predicts the wrong route.**
  * The quote decides "incremental" from `bytes/3` (`ChunkPlanner::estimate`, `ChunkPlanner.php:70-74`) because `ai_pipeline` is empty at first admission (`QuoteService.php:95-98`).
  * The route that actually runs uses the **provider-counted** tokens (`IncrementalPipeline.php:57-70`: `countTokens` at `:66` for any text above 14,000 bytes) and goes legacy when `tokens <= 14000 && chars <= 60000` (`:86-88`).
  * So a document between 42,001 and 60,000 characters whose real token count is at most 14,000 (it is, whenever it has at least 3.0 bytes per provider token) is **priced as incremental (floor included, 30+ credits) but executed on the legacy route**. The legacy route's one measured completed sample cost USD 0.101 for 8,000 tokens (`docs/tasks/ai-credits-implementation.md:18`). I do not know the true bytes-per-token ratio of the customer corpus (ASSUMPTION: English prose is roughly 4 characters per token, which would put such documents on the legacy route).
  * This affects workload billing only. Quote immutability means the customer pays the over-predicted band.
* **Stale-floor caveat.** The floors in `CreditEconomicsReport.php:33` are hard-coded and were derived on 2026-10-06 (`docs/tasks/ai-credits-implementation.md:3,25`). The default synthesis model is now `claude-sonnet-5-5` at USD 2/10 per million (`config/services.php:43`, `config/document_intelligence.php:171`), versus Sonnet 4.6 at 3/15 (`:168`). If the 10-06 floors used the older rate they are about one third too high today. They were not recomputed here (no execution). This could move the 30/80 thresholds; the direction is toward lower bands.
* **Smallest safe correction.** None implemented, because every correction lowers a customer charge (rule 8): (1) predict the route from the same signal that routes (provider count, or a conservative ratio calibrated on data), (2) derive the floor from the executed route. Documented as decision D-2 in the report.

### c. Paid reanalysis immediate-release gap on failure

* **Code.** `EntitlementService::releaseDocumentReservation` `app/Services/EntitlementService.php:105-140`: lines `110-112` return `false` whenever the document has `credit_accounted_at`. A document that was ever delivered always has it (`WorkspaceCreditService::claimCharge`, `:196-208`). A paid reanalysis reserves its own `reanalysis` operation on that already-settled document (`AiCreditAdmission.php:116-142`). The failure handlers call this method (`GenerateDocumentSummaryJob.php:503`, `GenerateInsightsJob.php:143, 386`, `DocumentController.php:148`), so for a reanalysis they release nothing.
* **What the customer sees.** The held credits stay in `monthlyCreditsReserved` / `savedCreditsReserved` and out of `availableCredits` until a sweep. The sweep runs on every `EntitlementService::summary()` (lazy, `:180`) and hourly (`ReleaseStaleReservations.php:26-33`). It releases an operation whose document is Failed or Needs Review (`:149`), and both failure paths set Needs Review (`GenerateDocumentSummaryJob.php:437, 495`; `GenerateInsightsJob.php`, catch block). So in practice the gap is "until the next balance read or hourly run", not 24 hours. It becomes up to 24h only if a failure leaves the document in another status.
* **Hazard behind it.** With `AI_CREDITS_NEEDS_REVIEW_POLICY=settle` the same sweep would **settle** the failed reanalysis (`EntitlementService.php:156-157`) even though the earlier published revision is unchanged. That policy is off by default; recorded as decision D-5.
* **Regimes.** Workload billing only. Legacy billing has no paid reanalysis.
* **Classification.** DEFECT (cause confirmed) with low, self-healing customer impact. The audit's "High" (C7) is overstated; "Medium-Low" is fair.
* **Smallest safe correction (implemented in Phase 4).** When the document is already settled, still release its open variable-price `ocr` and `reanalysis` holds, and never touch the settled `document` debit. Idempotent: `CreditAccountant::release` acts only from `status='reserved'` (`:159-161`).

### d. A fixed accepted quote settles even when actual provider cost is lower

* **Code.** `CreditAccountant::settle` `:131-141` debits `amount_reserved`; actual cost never enters. Stated as a design rule in `docs/tasks/ai-credits-implementation.md:38` and tested (`AiCreditsTest.php:339-354`).
* **Customer credits.** The customer pays the quoted band regardless of how cheap the run was. No double debit; one debit on delivery.
* **Classification.** INTENDED POLICY. A fixed price above API cost is the margin, not an accounting error. It is only a pricing-fairness concern when the **quote itself** is inflated (findings a and b), which is the real lever.
* **Smallest safe correction.** None. A usage rebate is a product decision (decision D-4).

### e. Historical cost dataset is incomplete

* **Verified from the repo.** `docs/tasks/ai-credits-implementation.md:12-25`: 593 runs on 41 documents, 317 (53%) without cost, five documents with fully known cost, none steady-state Ready except one legacy DOCX at USD 0.101. n = 5 is not a distribution.
* **Report-command shortcomings, verified in `app/Console/Commands/CreditEconomicsReport.php`.** `operations()` (`:135-156`) builds quantiles from `document_ai_runs` only, over **every** quote in the window including unfinished and released ones, excludes `provider_call_attempts` (which `OperationSpend::spentUsd` does count), prints p50/p90/p95/p99 but no p75, and drops quotes with zero known cost. `unquotedDocuments()` (`:161-163`) groups runs with a null `document_id` (Q&A) into one pseudo-document. These affect reporting only, never billing, so they are documented, not fixed here.
* **Not available to me.** Any production cost distribution. The local report could not be run (rule 1) and the audit's own run failed (no local database). Classification: CORRECT / data limitation.

### f. Workload billing is disabled by default

* **Code.** `config/ai_credits.php:9` `DOCINTEL_AI_CREDITS_ENABLED` default `false`. With it off, `QuoteService::enabled()` is false (`:16-19`), `CreditAccountant::mode` returns `legacy` (`CreditAccountant.php:33-35`), `aiCreditSummary` returns `['enabled'=>false]` (`EntitlementService.php:204-206`), and `OperationSpend::assertCallAllowed` returns `null` (`:229-231`).
* **Production value.** Not inspected. The checked-in `.env.example` documents it as `false` per `docs/tasks/ai-credits-implementation.md:78`.
* **Classification.** INTENDED POLICY and CORRECT. Dormant is the safe state for an unvalidated price list.

---

## Part 1b - Verdicts on the audit's other credit findings (C1-C7)

| Audit ID | Audit severity | My verdict | Why |
|---|---|---|---|
| C1 | High | **Re-rated Medium; split** | (a) is policy/calibration; (b) is partly a real quote-accuracy defect (route prediction). Neither is a wrong debit. |
| C2 | High | **Re-rated Low** | Reporting-only. Facts confirmed (see e). |
| C3 | Medium: "cost can exceed its ceiling, overrun may be larger under concurrency" | **INCORRECT / OVERSTATED** | `ProviderRequestAdmission` reserves a **hard ceiling** per request (input bound by serialized bytes, 1 byte at least 1 token, plus the full `max_tokens` at the highest applicable rate: `ProviderRequestAdmission.php:97-117`, `AiPricing::maximum` `:34-44`). `reserveAttempt` counts every `reserved`, `dispatched`, `settled`, `uncertain` attempt against the cap under the quote row lock (`OperationSpend.php:105-128`, `spentUsd` `:97-100`), so concurrent in-flight calls are summed before dispatch. `finishAttempt` records actual, which is at most the reservation unless the configured rate table is wrong. A residual risk exists only if configured prices or the 1-byte-per-token bound are wrong; neither was verified. |
| C4 | Medium | **CORRECT**, design gap, not a wrong debit | `admitOcr` `AiCreditAdmission.php:87-90` requires only OCR plus the 4-credit band. A scan can later quote 30/80 and be refused after OCR spend; OCR is then released (customer pays nothing, platform absorbs). |
| C5 | Medium | **Backend half OVERSTATED; frontend half UNVERIFIED** | The backend already exposes `savedCreditsReserved` and `savedCreditsAvailable` (`EntitlementService.php:213`). The frontend repo is out of scope and not available here. |
| C6 | Medium | **CORRECT** and consistent with the trace | Default is release; `settle` is opt-in. |
| C7 | High | **DEFECT, re-rated Medium-Low** | See c. |

---

## Part 2 - New findings from the independent trace

| ID | Finding | Classification | Wrong debit? | Fix in this PR? |
|---|---|---|---|---|
| N1 | Quote predicts incremental from `bytes/3`, runtime routes on provider count (see b) | PRICING / quote accuracy defect | Over-charges (inflated band) for affected documents | **No** (lowers charges; rule 8) |
| N2 | Comparison holds are never swept: `releaseFailures` kinds are `document/ocr/reanalysis` only (`EntitlementService.php:146`, `ReleaseStaleReservations.php:26`) | **DEFECT** | Retains 12 credits per orphaned comparison until the customer retries | **Yes** |
| N3 | Provider-rejected response: run row records zero usage (`AnthropicClient.php:587`), but the attempt row is finished with `null` and held at its full ceiling (`:783-785`, `OperationSpend.php:171-175`) | DEFECT (cost attribution; internal cap only) | No customer debit; burns the operation cap and can fail documents under 429/5xx storms | **No**: zeroing needs confirmation of the provider's billing for rejected requests (ASSUMPTION: rejected requests are not billed) |
| N4 | OCR admission does not test `credit_accounted_at`: a pre-rollout delivered scanned document reprocessed under workload billing gets a **new** OCR quote and a 20+ credit charge at the next publish (`AiCreditAdmission.php:74-101`, `WorkspaceCreditService.php:154-156`); a post-rollout delivered one re-OCRs free | POLICY inconsistency | Possible unexpected OCR charge, no customer confirmation step | **No** (changes what is charged; rule 8) |
| N5 | Re-run of a **Failed**, normal-route or Failed-delivered document takes the non-incremental branch (`DocumentReprocessor.php:53, 69-92`): no reanalysis quote, free; incremental Ready/Needs Review delivered documents are charged the band | Inconsistent policy by route/status | Customer-favourable | No |
| N6 | A refused 402 or too-large quote stays `quoted` and is reused later with its original band (`QuoteService.php:149-151`) | Risk | Stale price if the text changes; same text gives the same price | No |
| N7 | At the moment the flag is turned on, legacy 1-unit holds are not counted in `saved_reserved` (`CreditAccountant.php:53`), so a workspace can over-commit its saved balance by up to 10 credits per legacy hold; `debitSaved` clamps at zero but still ledgers the full debit (`:204-208`, `:142-143`) | Rollout edge case | Under-collects; ledger overstates the debit in the clamped case | No (flag-flip procedure item) |
| N8 | Re-run of a delivered document is bounded by the settled quote's *remaining* cap (`OperationSpend::activeQuote` `:196-207`). A free re-run can therefore stop with `budget_exceeded` when the first run already used most of the cap | Functional risk | No debit (documents fail, hold released) | No |

---
