# AI credits lifecycle trace

**Date:** 2026-10-23. **Branch:** `fix/ai-credits-independent-review` from `origin/main` (f21e307). **Method:** read-only code trace. Nothing was executed: no tests, no migrations, no database or provider access. Every statement below is **"traced in code"** unless it says otherwise. Line numbers refer to this commit.

Scope note: workload billing is behind `DOCINTEL_AI_CREDITS_ENABLED` (default `false`, `config/ai_credits.php:9`). With it off, billing is the legacy "one credit per published document" policy (`docs/billing-credits.md`). Both regimes are traced where they diverge. The effective production value of the flag was **not inspected** (no production access, by the task rules).

Vocabulary used throughout:

| Term | Meaning in code |
|---|---|
| quote | immutable price row in `operation_quotes` for one operation attempt (`app/Models/OperationQuote.php:13-37`) |
| reserved | `billing_operations.status='reserved'` with `amount_reserved` set; **no counter moves**, availability subtracts it (`CreditAccountant::reserve`, `:92-116`) |
| settled | the quoted amount is debited from the bucket counter and a ledger `debit` row is written (`CreditAccountant::settle`, `:119-148`) |
| released | reservation returned, ledger `release` row, no counter change (`:150-170`) |
| used | `subscription_usage_periods.ai_credits_used` (monthly) or the drop in `workspace_credits.ai_credits_remaining` (saved) |

---

## 1. How credits are granted

| Grant | Code | Notes |
|---|---|---|
| Free trial | `WorkspaceCreditService::grantTrial` `app/Services/WorkspaceCreditService.php:20-58` | Flag on: 20 saved AI credits via `CreditAccountant::grantSaved` (`:45-48`, `CreditAccountant.php:233-240`). Flag off: 5 legacy document units (`:49-54`). One grant per email / IP / fingerprint (`:25-31`); `insertOrIgnore` arbitrates a race (`:34`). |
| Subscription (monthly or annual) | `SubscriptionService::completePurchase` `app/Services/SubscriptionService.php:45-130` | Snapshot of the purchase's `ai_credits` is copied into each `subscription_usage_periods.ai_credits_allowed` (`:96`, `:112`). Annual = 12 monthly periods created up front (`:88-95`). Ledger credit row `period_ai_credits:<id>` is written only on creation (`:102-106`). Unused monthly credits do not roll over (a new period has its own allowance and `ai_credits_used=0`, `database/migrations/2026_10_07_000001_add_ai_credit_accounting.php:46-47`). |
| Mid-term re-purchase | `SubscriptionService.php:84-86` | Earlier overlapping period is truncated; the new period starts at 0 used. Customer-favourable: usage in the truncated period is not carried. |
| Referral reward | `WorkspaceCreditService::grantReferralReward` `:89-131` | Flag on: `ai_credits.grants.referral_reward_credits` (100) saved credits (`:114-118`). |
| Legacy saved units | counter `workspace_credits.documents_remaining` | Not rewritten. Converted lazily at 10 credits/unit **only when a debit needs them** (`CreditAccountant::debitSaved`, `:191-216`; ledger `ai_convert_out/in` `:209-213`). Visible balance counts them at the same rate (`balances`, `:52`). |
| Legacy period | `CreditAccountant::mode` `:31-42` | A paid period with `ai_credits_allowed IS NULL` stays on 1-unit accounting. |

## 2. How workload is estimated

`QuoteService::documentSignals` (`app/Services/AiCredits/QuoteService.php:92-116`) uses only local data:

* **Tokens** (`:95-98`): `ai_pipeline.tokens` if `count_method==='anthropic'`, otherwise `ChunkPlanner::estimate` = `ceil(strlen(text)/3)` (`app/Services/AI/Incremental/ChunkPlanner.php:70-74`). At the first admission `ai_pipeline` is empty, so the **estimate (bytes/3)** is what prices almost every first-run document.
* **Density** (`:99`): `ExtractionCapacity::density` (`app/Services/AI/Incremental/ExtractionCapacity.php:79-102`). `dense = spreadsheet OR numericRatio >= 0.18 OR tabularLineRatio >= 0.30` (`config/document_intelligence.php:61-62`). The file type alone (XLS/XLSX/CSV) forces `dense=true` (`:96-99`).
* **Predicted route and synthesis floor** (`:100-111`): `incremental` is predicted when `tokens > 14000` (`config/document_intelligence.php:37`) **or** `chars > 60000` (`config/document_processing.php:26`). For an incremental prediction the "floor" is the sum of three USD reservations from `AnthropicClient::synthesisReservation` (`app/Services/AnthropicClient.php:1281-1316`): primary synthesis + one degraded fallback + one repair.
* **Real route** is decided later, after the quote is reserved, from the **provider-counted** token total (`IncrementalPipeline::route`, `app/Services/AI/Incremental/IncrementalPipeline.php:38-125`; the legacy-route test is at `:86-88`). The quote's predicted route and the executed route can differ (finding F-b2 in the ledger).

OCR is estimated from the rasterised page count only (`QuoteService::classifyOcr`, `:119-129`).

## 3. How the band is chosen

`QuoteService::classify` (`:49-81`):

1. First band whose `max_tokens` covers the tokens (6k / 30k / 90k / 250k, credits 4 / 10 / 30 / 80, `config/ai_credits.php:15-20`). Above 250k: declined `document_too_large` (`:62`).
2. If `dense`, move up `dense_band_shift` (1) bands (`:64-66`). Capped at the top band; no decline here.
3. `needed = max(0.05, floorUsd) + tokens/1000 * 0.006` (`minCompletionCostUsd`, `:38-44`, config `:34-37`). While `providerCap(credits) = credits * 0.027` is below `needed`, move up another band; past the top band the document is declined `provider_cost_exceeds_top_band` (`:69-76`).

Consequence (arithmetic from config, floors not recomputed): 4-credit cap = USD 0.108, 10 = 0.27, 30 = 0.81, 80 = 2.16. Any document predicted incremental has `needed >= floor` of order 0.3-1.0 USD, which only fits the 30 or 80 bands. The 4 and 10-credit bands are therefore reachable only on the legacy route.

## 4. How the quote is produced

* `QuoteService::quoteDocument` (`:170-183`) -> `issue` (`:142-168`). `issue` locks existing quotes for `(kind, resource_id)`. A latest quote in `quoted` or `reserved` is returned **unchanged** (`:149-151`); a `settled` one is returned unless `$newRequest` (`:152-154`); otherwise a new attempt `kind:resource:N+1` is created (`:155-162`). A unique-violation race returns the winner (`:164-167`).
* Priced columns are immutable (`OperationQuote.php:15, 30-36`): later config changes cannot alter a stored charge.
* Confirmation: bands from `large` up need consent (`requiresConfirmation`, `:83-89`; `AiCreditAdmission::admitDocument` `:36-41`). The pending price is shown from `ai_pipeline.credit_quote` (`EntitlementService::aiCreditSummary`, `:216-217`) and accepted at `POST confirm-credits` (`DocumentCreditConfirmationController::store`, `:48-65`).
* A quote that was shown but refused (402) stays `quoted`. A later retry gets the **same, possibly stale** quote (`:149-151`). Only the priced band is stale; the 402 check still uses the live balance.

## 5. When credits are reserved

| Operation | Reservation point | Code |
|---|---|---|
| Document analysis | After local extraction, before the first paid call, in `dispatchIntelligenceChain` | `app/Jobs/Concerns/DispatchesIntelligenceChain.php:52` -> `AiCreditAdmission::admitDocument` (`:20-57`) -> `CreditAccountant::reserve` (`:44`) |
| OCR | After rasterisation, before any vision call | `ExtractDocumentTextJob.php:278` -> `admitOcr` (`:74-101`). Requires `ocr credits + 4` available (`:87-90`) |
| Paid reanalysis | At the user request, **only when the stored route is `incremental` and status is not Failed** | `DocumentReprocessor.php:53-55` -> `admitReanalysis` (`:116-142`) |
| AI comparison | At request time inside the controller transaction | `DocumentComparisonService.php:96-98` -> `EntitlementService::reserveComparison` (`:304-340`) -> quote 12 credits -> `reserve` (`:313`) |
| Q&A | Per question only if `ai_credits.qa.credits > 0` (default 0) | `QaGuard::admit` (`:106-129`) |
| Upload | **No** AI reservation; only `assertProcessing` requires the minimum band (4 credits) | `EntitlementService::reserveDocument` (`:244-258`) |

`reserve` runs under the workspace row lock and the quote row lock (`:94-97`). It is idempotent per quote (`:98-102`). Funding: monthly bucket first; saved credits only with no subscription or when `saved_fallback_during_subscription` is on (`fund`, `:78-90`). One operation, one bucket. The reservation is stored in `billing_operations` (updateOrInsert on `(kind, resource_id)`, `:106-110`), so there is **one row per kind per resource**, overwritten by each new attempt.

## 6. How actual provider usage is measured

At the single HTTP boundary `AnthropicClient::callWithRetry` (`app/Services/AnthropicClient.php:671-`):

1. `OperationSpend::assertCallAllowed` (`:694`; `OperationSpend.php:226-293`) requires a `reserved` or `settled` quote, kind/workspace/resource match, and `remaining cap > 0`. Without AI credits it returns `null`.
2. `ProviderRequestAdmission::assertAllowed` computes a **hard request ceiling** (`app/Services/AI/ProviderRequestAdmission.php:97-117`). Input tokens are bounded by serialized bytes (1 byte >= 1 token), plus the full `max_tokens` output, at the highest applicable rate (`AiPricing::maximum`, `app/Services/AI/AiPricing.php:34-44`).
3. `OperationSpend::reserveAttempt` (`:105-128`) refuses the call if `spent + ceiling > quote cap` and inserts a `provider_call_attempts` row `reserved`.
4. `markDispatched` (`:131-151`), then after the response `finishAttempt` (`:154-177`) with `AiPricing::estimate(model, response.usage)` (`AnthropicClient.php:783-785`). A dispatched attempt with no usage becomes `uncertain` and **keeps its full ceiling** against the cap. An undispatched one is `released` free (`:180-193`).
5. `spentUsd` (`OperationSpend.php:92-102`) = run rows with no attempt id (known cost, unknown at USD 0.05 each) + attempt rows (`settled_microusd` or the reserved ceiling).
6. Each response also writes a `document_ai_runs` row with `operation_quote_id`, `estimated_cost_usd`, tokens (`AnthropicClient.php:507-571`).
7. Calls with no quote (Q&A with zero credits, comparison on a legacy reservation) use an internal per-scope cap through `internalScope` / `reserveInternalAttempt` (`OperationSpend.php:20-89`): Q&A USD 2/workspace/day, comparison and document `budget_max_usd` (USD 10).

The measured cost never feeds back into the customer price.

## 7. When credits are permanently deducted (settled)

| Regime | Charge point | Code |
|---|---|---|
| Revisions on (`DOCINTEL_REVISION_V1`, on in production per `docs/billing-credits.md:41`) | Inside the publish transaction, under the document lock | `DocumentRevisionPublisher::commit` `app/Services/Intelligence/DocumentRevisionPublisher.php:117-210`, charge at `:203` |
| Revisions off, incremental route | At the Ready transition in the summary job | `GenerateDocumentSummaryJob.php:297-317` (charge `:302-306`) |
| Revisions off, normal route | `GenerateInsightsJob` Ready transition | `GenerateInsightsJob.php:284`; unchanged re-upload skip path `:72-93` |

`WorkspaceCreditService::accountForReadyDocument` (`:149-189`):

* If any `document`/`ocr`/`reanalysis` operation holds an `amount_reserved` (`hasAiOperation`, `:210-214`) it calls `CreditAccountant::settleDocument` (`:173-181`), which settles **every open** one of those three kinds (`:176-178`). `$ready=false` (merge only) never debits (`:154`).
* Then, if the document's own charge is complete and `credit_accounted_at` is null, it claims it with one conditional update (`claimCharge`, `:196-208`).
* Legacy accounting (no AI operation): claim then `settleDocument` one unit (`:166-188`).

`settle` debits **exactly `amount_reserved`**, never the measured cost (`CreditAccountant.php:131-141`). It runs under the workspace lock and only from `status='reserved'` (`:126-130`), so repeated calls debit once. Monthly bucket: `ai_credits_used += amount` (`:134`). Saved bucket: `debitSaved` (`:191-216`).

## 8. Failed, cancelled, retried, incomplete processing

| Situation | What happens | Code |
|---|---|---|
| Queue retry / duplicate delivery | Same quote, same reservation, no new debit | `issue` `:149-151`; `reserve` `:98-102` |
| Job retries (3 tries, 60s/300s) | Reservation held across attempts and kept alive (`updated_at` bumped) | `EntitlementService::reserveDocument` `:249-258`; `docs/billing-credits.md:89-95` |
| Analysis fails terminally (normal route) | status Needs Review/Failed, `releaseDocumentReservation` | `GenerateInsightsJob.php:113, 143, 386` |
| Synthesis fails terminally (job exception) | status Needs Review, revision failed, `releaseDocumentReservation` | `GenerateDocumentSummaryJob.php:478-509` (release `:503`) |
| Synthesis fails with a typed failure (not a job exception) | status Needs Review, revision failed, **no immediate release**; the sweep releases it | `GenerateDocumentSummaryJob.php:424-444` |
| `releaseDocumentReservation` on an already-settled document | **Returns false immediately** | `EntitlementService.php:110-112` |
| Document deleted | soft delete then `releaseDocumentReservation` (no-op if settled) | `DocumentController.php:140-148` |
| Upload dispatch never confirmed | `releaseDocument` of all three kinds | `UploadDispatchRecovery.php:47` |
| Admission refused (too large, 402, OCR page cap) | Document set Failed, all three kinds released | `AiCreditAdmission::refuse` `:103-109` |
| Sweep (lazy on every `summary()` read, hourly command) | Releases `document/ocr/reanalysis` reservations that are older than 24h, whose document is gone, or Failed/Needs Review; with `needs_review_policy=settle` a Needs Review one is **settled** | `EntitlementService::releaseFailures` `:142-173`; `ReleaseStaleReservations.php:26-33` |
| Comparison job fails after retries | `releaseComparison` | `CompareDocumentsJob.php:94-110` (`failed()`; release at `:106`) |
| Comparison job lost without `failed()` | **Never swept**; the sweep's kinds are `document/ocr/reanalysis` only | `EntitlementService.php:146`; `ReleaseStaleReservations.php:26` |
| Provider rejected the request (non-2xx, no usage) | Run row records zero usage; the attempt row is finished with `null` and stays `uncertain` at its full ceiling | `AnthropicClient.php:587` vs `:783-785`, `OperationSpend.php:171-175` |

## 9. Reanalysis, OCR, comparison and Q&A charging

* **Paid reanalysis** (`admitReanalysis`, `AiCreditAdmission.php:116-142`): only on the incremental route; summary-only refreshes are free (`:118`). A delivered document (`credit_accounted_at` set or a completed document operation, `:121-122`) gets a separate `reanalysis` quote at the document's **current band** (or a fixed price, `QuoteService::reanalysisCredits` `:131-135`), `newRequest=true` so a settled earlier attempt is re-quoted (`:152`). Customer requests with `confirmCredits !== null` must accept a large quote (`:126-130`). The reservation is settled by the next publish (`settleDocument` loops over `reanalysis`, `:176`).
  * A **Failed** delivered document re-runs through the non-incremental branch (`DocumentReprocessor.php:53, 69-92`): no `reanalysis` quote, no new charge. A **normal-route** delivered document re-runs the same way: no charge. Their provider spend remains bounded by the settled quote's remaining cap (`OperationSpend::activeQuote` `:196-207`).
* **OCR** (`admitOcr` `:74-101`): own quote, 20 credits plus 1 per page beyond 20, max 60 pages (`config/ai_credits.php:42-50`). **It does not test `credit_accounted_at`.** On a document that was delivered before the rollout (no OCR quote exists) a reprocess creates and reserves a fresh OCR quote that settles at the next publish. On a document whose OCR quote is already settled, `issue` returns the settled quote (`:152-154`) and `reserve` returns idempotently (`:98-102`), so re-OCR is free but bounded by the settled cap.
* **AI comparison** (`quoteComparison` `:197-203`): 12 credits, `quote -> reserve -> settle|release`. The structured (non-AI) comparison is free (`EntitlementService::reserveComparison` `:306-308`).
* **Q&A**: zero customer credits by default (`config/ai_credits.php:59`). `QaGuard` applies 100/workspace/day, 30/user/hour and a USD 2/workspace/day ceiling (`:106-117, 140-144`).

## 10. Ledger vs available balance vs frontend billing API

Sources of truth (code, not the audit's wording):

| Quantity | Source | Code |
|---|---|---|
| Monthly allowed / used | period row | `CreditAccountant::balances` `:55-60` |
| Monthly reserved | `SUM(amount_reserved)` of open operations for the period | `:56` |
| Monthly remaining | `max(0, allowed - used - reserved)` | `:58` |
| Saved total | `ai_credits_remaining + documents_remaining * 10` | `:52` |
| Saved reserved | open operations with `usage_period_id IS NULL` and `amount_reserved` set | `:53` |
| Saved available | `max(0, saved - savedReserved)` | `:62` |
| Available (what admission uses) | monthly remaining while a period exists (saved only with the fallback flag); otherwise saved available | `available` `:67-75` |
| Ledger | append-only `credit_ledger`, unique `reference` with `insertOrIgnore` | `CreditLedger.php:12-22` |

The API block is `ai_credits` inside `GET /api/workspace/credits` and `GET /api/billing` (`EntitlementService::aiCreditSummary`, `:201-218`; controllers `WorkspaceCreditController::show`, `BillingController::show`).

Traced consistency properties:

1. `available` shown to the UI is the same function admission uses, so the displayed available amount matches the admission decision at read time. (`aiCreditSummary:214` calls `available`.)
2. `balances` ignores legacy 1-unit reservations (`amount_reserved IS NULL`) when computing saved reserved (`:53`). A workspace holding a legacy reservation when the flag is turned on can over-commit its saved balance by one unit (10 credits). `debitSaved` then clamps at zero and logs a warning (`:204-208`) but still writes a ledger `debit` of the full amount (`:142-143`). This under-collects, not over-collects, and is limited to the rollout moment.
3. The ledger is not read by any customer-facing path; counters and open operation rows are authoritative. The ledger identity `reserve = debit + release + open` holds only if every release/settle writes its row, which the code does (`:112-113, 142-143, 164-165`).
4. `AuditCreditSettlements` (`app/Console/Commands/AuditCreditSettlements.php:34-48`) checks double document debits and held reservations; it does **not** include `comparison`, and does not reconcile counters with the ledger.

## 11. Callers inspected

Jobs: `GenerateInsightsJob`, `GenerateDocumentSummaryJob`, `MergeDocumentEvidenceJob` (`:304`, `$ready=false`), `ExtractDocumentTextJob`, `OcrPageBatchJob` (`:108`), `AnalyzeEmbeddedVisualsJob` (`:76`), `GenerateEmbeddingsJob` (`:84`), `ResumeAfterCreditConfirmationJob`, `CompareDocumentsJob`, `Concerns/GuardsDocumentIntelligence` (`:68`), `Concerns/DispatchesIntelligenceChain`. Services: `DocumentReprocessor`, `DocumentRevisionPublisher`, `UploadDispatchRecovery`, `DocumentComparisonService`, `SubscriptionService`, `WorkspaceCreditService`, `EntitlementService`, `AnthropicClient`, `OperationSpend`, `QaGuard`. Controllers: `DocumentController::destroy`, `DocumentUploadController`, `DocumentReprocessController`, `DocumentCreditConfirmationController`, `WorkspaceCreditController`, `BillingController`, `CreditPurchaseController`. Commands: `ReleaseStaleReservations`, `AuditCreditSettlements`, `CreditEconomicsReport`, `ResumeDocumentIntelligence`. Models: `OperationQuote`, `WorkspaceCredit`.

## 12. What this trace could not establish

* Production flag values, plans, balances, real cost per document. No production access was used.
* Current synthesis-reservation USD floors. The report command hard-codes them (`CreditEconomicsReport.php:33`) and `docs/tasks/ai-credits-implementation.md:25` derived them on 2026-10-06; the default synthesis model has since moved to `claude-sonnet-5-5` (`config/services.php:43`, rates `config/document_intelligence.php:171`). They were not recomputed here (no execution allowed).
* Token ratio of real documents (bytes per provider token). The code uses bytes/3 for quotes and a provider count for routing; any ratio quoted in the ledger is labelled ASSUMPTION.
