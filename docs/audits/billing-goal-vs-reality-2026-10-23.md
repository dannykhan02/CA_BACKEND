# Billing goal vs reality

**Date:** 2026-10-23. **Commit audited:** `e394eef` (`origin/main`, after PR #86). **Method:** read-only code reading. Nothing was executed: no tests, no artisan, no database, no provider, no production access. Line numbers refer to this commit.

**Goal audited.** Credits should scale with workload: users get more credits, and each document consumes credits in proportion to the work the system does (a small file costs little; a very large document might cost about 10 credits).

**Labels.** `VERIFIED IN CODE` = read at the cited line (or named test, which is written but was **not run** here). `NOT VERIFIED` = needs production data or a run. `NOT DECIDED` = a product decision with no recorded answer. Anything I could not check is in section 10.

This document builds on `docs/audits/ai-credits-lifecycle-trace.md`, `ai-credits-findings-ledger.md`, `ai-credits-pricing-analysis.md` and `docintel-ai-credits-independent-review.md` (PR #83). Their line numbers were re-checked here against the current commit; where code moved, the numbers below are the current ones.

---

## 0. The answer in ten lines

1. **Today (flag off) the charge does not scale with workload at all.** Every document costs exactly one document unit when its revision publishes, whatever its type, size, page count or route. VERIFIED IN CODE (§1).
2. **The workload machinery exists but is dormant.** `DOCINTEL_AI_CREDITS_ENABLED` defaults to `false` (`config/ai_credits.php:9`). It prices by estimated tokens into four bands, 4 / 10 / 30 / 80 credits, plus OCR by page count (§2).
3. **"A very large document ≈ 10 credits" is not what the bands say.** 10 credits is the *standard* band (6k–30k tokens). A very large document (90k–250k tokens) is 80 credits (`config/ai_credits.php:15-20`). Reaching "about 10" is a scale decision (D-8), not a bug.
4. **"Users receive more credits" is true in number, not in documents.** Starter gets 100 AI credits/month versus 20 documents today; that is 10 standard documents, 3 large or 1 very large (§5). Professional: 250 credits versus 100 documents, so 25 standard, 8 large, 3 very large. Small documents get cheaper, mid-size get more expensive.
5. **The price is a fixed quote, not metered cost.** It is derived from token *estimates* (bytes ÷ 3) and settled at exactly the quoted amount; measured provider cost never changes the charge (`CreditAccountant.php:131-141`).
6. **Known mispricing risk:** the quote predicts the route from the estimate but the pipeline routes on the provider's real token count, so a document can be priced as large and run as small (finding N1, unfixed, needs your approval to change).
7. **No production cost data was available to me.** Margins are therefore ceiling-based, not measured; n = 5 documents were behind the earlier pricing analysis. §7 gives read-only commands to measure it.
8. **Free work is free in code.** Tesseract location (image and PDF), downloads and source viewing contain no credit or entitlement code (§4).
9. **Enabling is global, not per workspace.** There is no allow-list or cohort switch; the flag is read in 18 places (`grep QuoteService::enabled|ai_credits.enabled`). A "named cohort" rollout needs new code (§9).
10. **Recommendation:** measure first (§7), then add log-only cost rollups, then fix N1 and choose the scale, then enable with a cohort mechanism. Do not enable the flag as-is (§9).

---

## 1. Current production behaviour (`ai_credits.enabled = false`)

Production's flag value was **not inspected** (no production access); the default is `false` (`config/ai_credits.php:9`), and `QuoteService::enabled()` reads only that config (`QuoteService.php:16-19`). `CreditAccountant::mode()` returns `'legacy'` whenever it is off (`CreditAccountant.php:31-35`). `AiCreditAdmission::admitDocument`, `admitOcr` and `admitReanalysis` all return immediately in legacy mode (`AiCreditAdmission.php:22`, `:76`, `:118`). NOT VERIFIED that production matches the default.

`DOCINTEL_REVISION_V1` defaults to `false` in config (`config/document_intelligence.php:7`); `docs/billing-credits.md:41` states it is on in production. NOT VERIFIED here. The table below is for revisions **on**; the revisions-off charge points are in the last row.

### 1.1 What happens, for every document type

| Step | What happens | Evidence |
|---|---|---|
| Upload | `reserveDocument` runs: a workspace row lock, then, for a settled document nothing, otherwise a `billing_operations` row `reserved` and a ledger `reserve` row of **1**. No counter changes. A subscriber's reservation is tied to the usage period, otherwise to saved credits. A workspace with no remaining units gets HTTP 402. | `DocumentUploadController.php:116`; `EntitlementService.php:261-299`, `assertProcessing :248-258` |
| During processing | The reservation is only kept alive (`updated_at` touched) by later stages; it is not re-reserved. | `ExtractDocumentTextJob.php:57`, `OcrPageBatchJob.php:108`, `GenerateEmbeddingsJob.php:84`, `AnalyzeEmbeddedVisualsJob.php:76`, `Concerns/GuardsDocumentIntelligence.php:68` (the last only `if (! credit_accounted_at)`) |
| Charge (settle) | Exactly **1 unit**, inside the publish transaction under the document lock; `credit_accounted_at` is claimed by one conditional update so concurrent or repeated publishes charge once. | `DocumentRevisionPublisher.php:203` → `WorkspaceCreditService::accountForReadyDocument :149-189` (claim `:196`); doc `docs/billing-credits.md:9-30`; test `CreditChargeOnPublishTest::test_publishing_a_revision_charges_exactly_one_credit`, `::test_publishing_twice_charges_once` (not run) |
| Release | Failed / Needs Review / deleted / retries exhausted: reservation returned, no charge. Hourly backstop for anything stranded > 24 h. | `EntitlementService.php:106-144` (`releaseDocumentReservation`), `:146-` (`releaseFailures`); `app/Console/Commands/ReleaseStaleReservations.php`; `docs/billing-credits.md:73-91` |
| Reprocess of a settled document | No new reservation and no new charge: `reserveDocument` returns early for a settled document, and `accountForReadyDocument` returns at `credit_accounted_at !== null`. | `EntitlementService.php:282-288`; `WorkspaceCreditService.php:166-168`; test `CreditChargeOnPublishTest::test_a_later_revision_publishing_for_a_settled_document_charges_nothing` |
| Reanalysis (paid) | Not a thing in legacy: `admitReanalysis` returns at once, so it reserves nothing. | `AiCreditAdmission.php:118` |
| Revisions off | Charge falls to the Ready transition (`GenerateInsightsJob` on the normal route, `GenerateDocumentSummaryJob` on the incremental route). A failure after Ready is then possible without refund. | `docs/billing-credits.md:39-70` |

### 1.2 By document type and size

The charge path is **type-agnostic and size-agnostic**: it keys on the document, never on content (`accountForReadyDocument` has no size input).

| Type / shape | Reserved | Charged | Released | Does size/pages change the charge? |
|---|---|---|---|---|
| Text PDF, small (normal route: ≤ 14,000 tokens and ≤ 60,000 chars) | 1 at upload | 1 at publish | on failure | **No** |
| Text PDF, large (incremental route: above either limit) | 1 | 1 | on failure | **No.** Only the *provider spend* grows (many chunk calls). Route rule: `IncrementalPipeline.php:84-90`; limits `config/document_intelligence.php:37`, `config/document_processing.php:26` |
| Scanned PDF (OCR) | 1 | 1 | on failure | **No.** OCR vision calls (one per page, batches of ≤ 3 pages, `config/document_processing.php:18`) are paid by us and not billed in legacy: `admitOcr` returns `true` in legacy (`AiCreditAdmission.php:76-78`; call site `ExtractDocumentTextJob.php:278`). OCR is on by default per workspace (`workspace_settings.ocr_enabled` default `true`, migration `2026_08_04_130002`). |
| JPG / PNG | 1 | 1 | on failure | **No.** One vision call; same as above |
| Word (DOCX) | 1 | 1 | on failure | **No** |
| Excel (XLSX) | 1 | 1 | on failure | **No** (density only matters under AI credits, §2) |
| Optional visual (chart image) jobs | none | none | n/a | **No.** `ProcessDocumentVisualJob` has no credit code and only runs once the document is Ready (see §3) |
| Comparison | legacy comparison allowance, see `EntitlementService::reserveComparison` | per comparison unit | on failure | not size-based |

**The only things in legacy that depend on size are limits, not prices:**

* upload ≤ 20 MB (`config/document_processing.php:4`), PDF ≤ 1000 pages (`:11`), image ≤ 100M pixels (`:17`);
* the plan's storage allowance (`EntitlementService.php:257`);
* the provider budget per document: base USD 0.50, hard maximum USD 10 (`config/document_intelligence.php:152-154`). This caps *our* cost, not the customer's charge;
* on the normal route the prompt text is truncated at 60,000 chars (`AnthropicClient.php:929,953,963`); documents longer than that are routed to the incremental route instead (`IncrementalPipeline.php:84-90`), so nothing is silently cut. VERIFIED IN CODE (route comment `:81-84`).

**Consequence for the goal (VERIFIED IN CODE):** a 200-page report and a one-page memo both consume exactly one unit. A customer who uploads only very large documents costs us (up to the USD 10 cap) far more than one who uploads small ones, at the same price.

---

## 2. Behaviour with AI credits enabled

### 2.1 Quote inputs (`QuoteService::documentSignals`, `:92-116`)

| Input | Source | Used for |
|---|---|---|
| Tokens | `ai_pipeline.tokens` if `count_method === 'anthropic'`, else `ceil(strlen(text)/3)` (`ChunkPlanner.php:73`) | band |
| Density | `ExtractionCapacity::density`: spreadsheet type, numeric ratio ≥ 0.18 or tabular-line ratio ≥ 0.30 (`config/document_intelligence.php:61-62`) | +1 band |
| Predicted route | `tokens > 14000` or `chars > 60000` | a synthesis cost "floor" that can force a higher band |
| OCR pages | rasterised page count (`classifyOcr`, `:119-129`) | OCR quote |
| Chunks, bytes, visual jobs | **not inputs.** Chunk count is not used; file bytes are not used (text length is); visual jobs are never priced. |

### 2.2 Formula (`QuoteService::classify`, `:49-81`; config `config/ai_credits.php:15-37`)

1. Band = first whose `max_tokens` covers the tokens: ≤ 6,000 → **4**; ≤ 30,000 → **10**; ≤ 90,000 → **30**; ≤ 250,000 → **80**; above 250,000 declined (`document_too_large`).
2. Dense document: +1 band (`dense_band_shift`), capped at the top band.
3. Gate: required provider cost `needed = max(0.05, synthesisFloor) + tokens/1000 × 0.006`. While the band's provider cap (`credits × USD 0.027`) is below `needed`, move up another band; beyond the top band the document is declined.
4. **Caps (USD):** 0.108 / 0.27 / 0.81 / 2.16 for 4 / 10 / 30 / 80 credits (arithmetic from `provider_cost_usd_per_credit = 0.027`).
5. **OCR:** 20 credits, +1 per page beyond 20, hard stop at 60 pages (`config/ai_credits.php:42-52`, `max_pages` declines, never silently OCRs). Provider cap = `max(pages × 0.02, 0.05)` (`:52` and `classifyOcr`).
6. **Comparison:** 12 credits. **Reanalysis:** document's current band (or fixed 10). **Q&A:** 0 credits, with abuse caps (`:59`).
7. **Floors:** a document needs at least 4 credits available to start (`minimumRequiredCredits`, `assertProcessing :251-253`).

Confirmation: bands `large` and up need the customer's explicit acceptance before anything is reserved (`requiresConfirmation`, `:83-89`; `AiCreditAdmission.php:36-41`; `DocumentCreditConfirmationController`).

### 2.3 Price shown to the user

From the stored quote, via `ai_pipeline.credit_quote`, exposed in `ai_credits` of `GET /api/workspace/credits` and `GET /api/billing` (`EntitlementService::aiCreditSummary`, `:218-`). The quote row is immutable (`OperationQuote::IMMUTABLE`, `app/Models/OperationQuote.php:16`), so config changes never rewrite a historical charge. A refused-but-shown quote is reused on retry and can be stale (`QuoteService::issue :149-151`). VERIFIED IN CODE.

### 2.4 When actual cost exceeds the quote

* The customer is still charged **exactly the quoted credits** (`CreditAccountant::settle :131-141`). No rebate if cheaper, no top-up if dearer. (D-4)
* Overspend is *prevented*, not billed: before each provider request `OperationSpend::reserveAttempt` refuses a call if `spent + hard request ceiling > quote cap` (`OperationSpend.php:105-128`). The ceiling uses the request's serialized bytes and full `max_tokens` at the highest rate (`ProviderRequestAdmission.php:97-117`, `AiPricing::maximum :34-44`).
* If the cap cannot fund the next request the document stops with `budget_exceeded` and the reservation is released (no charge). A one-page OCR scan used to hit this; fixed by the cap floor of PR #83 (`config/ai_credits.php:52`).
* NOT VERIFIED: whether the 4- and 10-credit caps can fund the largest single request on the production models (R-2 in the review).

---

## 3. Workload drivers vs what is recorded

Every Anthropic response is written to `document_ai_runs` at one place, `AnthropicClient::parseAndRecord → DocumentAiRun::create` (`AnthropicClient.php:495`, `:538-` ), with `workspace_id`, `document_id`, `purpose`, `model`, `input_tokens`, `output_tokens`, cache tokens, `estimated_cost_usd` (null when the response has no usage, `:547`), `chunk_id`, `request_attempt`, `duration_ms`, `failure_class`, `provider_request_id`, `partial`, `operation_quote_id` and `comparison_id` (columns: migrations `2026_08_05_091807`, `2026_10_04_000003`, `2026_10_07_000001`). The cost is an *estimate from tokens × the configured price*, not the invoice.

| Provider call | Purpose / where | Tokens + cost stored per document? | Notes |
|---|---|---|---|
| Anthropic text: insights, entities, risks, deadlines, classification, summary, synthesis, repair | `parseAndRecord` | **Yes**, per call, summable by `document_id` | VERIFIED IN CODE |
| Anthropic chunk extraction (incremental) | same, with `chunk_id` | **Yes**, per chunk and per attempt | `ProcessDocumentChunkJob` |
| Anthropic vision OCR | `purpose='ocr'` through `extractTextFromImage` (`AnthropicClient.php:414-441`) | **Yes**, per page call | |
| Anthropic chart vision (visual jobs) | `purpose='chart_vision'` (`:448-`) | **Yes**, per visual | |
| Anthropic comparison, Q&A | `document_comparison`, `document_qa` | **Yes** (by `comparison_id` / workspace), not by document for Q&A | |
| Anthropic `count_tokens` | `AnthropicClient::countTokens` (`:126-`) | **Not recorded** (no run row; a free endpoint, not verified as free in billing terms) | NOT VERIFIED |
| Provider-rejected requests (4xx/5xx) | run row written with zero usage | Tokens **0**, cost null; the spend ledger keeps the full ceiling (finding N3) | the invoice may or may not charge these, NOT VERIFIED |
| Voyage embeddings | `GenerateEmbeddingsJob` → `VoyageEmbeddingClient` | **Not recorded.** The client file has no usage or token field; only an error log (`VoyageEmbeddingClient.php:35`) | a cost not covered by any meter |
| Tesseract (image/PDF location), pdftoppm, GD | local CPU | **Not recorded as a cost.** Stage timing only, in `processing_jobs` | free to the customer by design (§4) |
| Storage (R2) | | bytes stored per document (`size_kb`), plan allowance | not a per-document cost |

Per-job/per-document roll-up: there is **no single "document cost" column**. It must be computed by summing `document_ai_runs.estimated_cost_usd` per `document_id` (what `docintel:credit-economics-report` and `docintel:ai-usage-report` do). Page count is stored for PDFs only (`documents.pages`, set at `ExtractDocumentTextJob.php:97`; images stay 0 from upload). The route and token count are in `documents.ai_pipeline` (`tokens`, `count_method`, `route`, `IncrementalPipeline.php:77,90`).

---

## 4. Free work

| Item | Charged? | Evidence |
|---|---|---|
| `LocateImageTextJob` / `ImageTextLocator` (tesseract on JPG/PNG) | **No** | Neither file nor the runner references any credit, entitlement or billing class (checked by searching the files for `Entitlement`, `Credit`, `billing`: no matches). Tests assert zero ledger rows: `ImageTextLocationStageTest.php:135,172` (not run) |
| `LocatePdfTextJob` / `PdfTextLocator` / `PdfPageRenderer` (new, PDF) | **No** | No credit reference in any of them; `PdfTextLocationTest::test_extraction_analysis_and_credits_are_untouched` asserts 0 `credit_ledger` and 0 `billing_operations` rows (`:138`; not run) |
| Stage record | none | recorded as optional stage `image_text_location`, never required, never failed (`LocateImageTextJob.php`, `LocatePdfTextJob.php`) |
| Downloads (`DocumentDownloadController`) | **No** | no credit references |
| Viewing / source lookups (`DocumentSourceReferenceController`, `...CellWindow`, `...DocxWindow`) | **No** | no credit references |
| AI-credits-on Q&A | Free (0 credits) by default, abuse-capped | `config/ai_credits.php:61-62` |

Caveat: free to the *customer* is not free to *us*: Tesseract/pdftoppm use worker CPU and are unmetered (§3).

---

## 5. Gap table

Requirement columns: Implemented = code exists. Verified = checked by reading code *and* either a named test (not run here) or production data.

| # | Goal / requirement | Implemented | Verified | Missing | Decision needed |
|---|---|---|---|---|---|
| 1 | Credits scale with document size | Yes, behind a flag: 4 bands by tokens (+dense, +OCR pages) | Code read; margins are ceiling-based; **no production cost data** | Flag is off; no per-workspace rollout; no measured calibration | D-1, D-8 |
| 2 | Small file costs little | Yes (4 credits for ≤ 6k tokens; legacy: 1 unit for all) | Code; NOT VERIFIED against real cost | Calibration | D-8 |
| 3 | Very large document ≈ 10 credits | **No.** Top band is 80; 10 is the standard band | Arithmetic from config | Either a scale change (×1 vs ×10 headline) or different bands | D-8 (credit scale), D-1 |
| 4 | "Users receive more credits" | Yes numerically: Starter 100, Professional 250 AI credits/month vs 20 / 100 documents | Arithmetic: Starter 25 / 10 / 3 / 1 documents of band 4 / 10 / 30 / 80; Professional 62 / 25 / 8 / 3 | Annual plans are below the 70% margin target at the ceiling (69.1% / 67.1%, from the earlier pricing analysis, **not recomputed here**) | D-9, D-10 |
| 5 | Price shown before charge, with consent for large | Yes (quote + `confirm-credits` for large+) | Code (`DocumentCreditConfirmationController`) | Stale-quote reuse (N6) | none blocking |
| 6 | Charge only on delivery | Yes (publish transaction) | `CreditChargeOnPublishTest` suite (not run) | Hardly any | none |
| 7 | Charge proportional to *actual* work | **No**, by design: fixed quote, no metering feedback | `CreditAccountant::settle :131-141` | A usage-based settlement or a measured calibration loop | D-4 |
| 8 | OCR work priced | Yes: 20 + 1/page above 20, cap 60 pages | Code | No measured per-page cost; legacy mode charges nothing for OCR | D-6 |
| 9 | Optional visual (chart) analysis priced | No (free, optional) | `ProcessDocumentVisualJob` has no credit code | Not priced; cost is bounded by the document budget | not asked |
| 10 | Route prediction matches execution | **No** (N1): quote uses bytes/3, the pipeline uses the provider count | Ledger finding N1 | Fix + recompute floors | D-2 |
| 11 | Per-document measured cost visible | Partial: sum of runs, no rollup, no Voyage | §3 | Rollup; embeddings usage | proposal §7.3 |
| 12 | Safe staged rollout | **No.** Flag is global | 18 global reads; no allow-list | Per-workspace/cohort gate | new decision |
| 13 | Failed work is free | Yes for the three paths fixed in PR #83 | tests `AiCreditsIndependentReviewTest` (not run) | R-1: no cumulative unpaid-spend cap per document | D-13 |

---

## 6. Failure and edge cases

| Case | Behaviour | Evidence / status |
|---|---|---|
| Failed or exhausted retries | Reservation released, no charge. Retries hold one reservation | `CreditChargeOnPublishTest::test_exhausted_retries_release_the_reservation_without_charging`, `::test_transient_failure_then_success_on_retry_charges_once_and_holds_one_reservation` (not run) |
| **Failed chunks / partial coverage** | The pipeline merges the surviving chunks and continues; the gap is recorded in `ai_pipeline.coverage` (`failed_chunks`, `comprehensive=false`) and on the published revision. **The publisher does not check coverage, so the charge is taken anyway** | `IncrementalPipeline.php` (pump/finalize) and `EvidenceBudget.php:95-104`; `DocumentRevisionPublisher.php:203` charges unconditionally. VERIFIED IN CODE. A customer can be charged for a revision missing a chunk. NOT DECIDED (recommendation in PR #85 report: settle only if comprehensive, or retry once) |
| Chunk job re-reserved after a worker kill | `MaxAttemptsExceededException` (tries = 1), chunk marked `uncertain` | `ProcessDocumentChunkJob.php:35,336-343`; production occurrence not determined |
| Double charge | Prevented: one conditional claim on `documents.credit_accounted_at`; ledger references unique | `WorkspaceCreditService.php:196-208`; `CreditLedger` unique reference; `WorkspaceAiCreditsConcurrencyTest::test_two_workers_settling_the_same_document_debit_once_and_never_go_negative`; audit command `credits:audit-settlements` (not run) |
| Refund correctness | There is **no customer refund**. "Refund" = release of an unsettled hold (no counter moved). A settled document is never refunded, by policy (`docs/billing-credits.md:101-106`). Released variable-price holds: reserve = debit + release + open per ledger | `CreditAccountant::release :150-170`; `FinalAuditInvariantsTest::test_concurrent_looking_settle_and_release_never_both_apply` (not run) |
| Free tier / trial abuse | One trial grant per email, IP **or** fingerprint (`TrialGrant` lookup then `insertOrIgnore`); trial = 5 units legacy or 20 AI credits | `WorkspaceCreditService::grantTrial :20-58`. NOT VERIFIED: shared IPs (offices, carrier NAT) are refused a trial; VPN/new-email evasion is not prevented. Ceiling cost of one AI trial grant = 20 × USD 0.027 = USD 0.54 |
| Concurrent uploads at the allowance limit | Legacy: `reserveDocument` takes the workspace row lock then `assertProcessing`, which subtracts open reservations, so a second upload sees the first one's hold. AI mode: `reserve` under the workspace + quote locks | `EntitlementService.php:261-299`, `summary :192-`; `WorkspaceAiCreditsConcurrencyTest::test_two_uploads_cannot_overdraw_one_balance` (AI mode; not run). **No dedicated legacy-mode parallel-upload test found** |
| Reprocess of a settled document | No new charge in legacy (§1.1). AI mode: OCR re-quote may charge a pre-rollout scan again (N4); a failed paid reanalysis now releases its hold (PR #83) | `AiCreditAdmission.php:74-101`; ledger N4 |
| Reprocess re-runs OCR (provider cost to us, none to customer) | Yes | `DocumentReprocessor.php:112-` clears text and re-extracts |
| Orphaned comparison hold | Now swept after 24 h (PR #83) | `AiCreditsIndependentReviewTest` (not run) |
| Needs Review | `needs_review_policy=release` (default): no charge. `settle` would charge a failed reanalysis (R-3) | `config/ai_credits.php:69` |
| Switching the flag on with legacy holds open | Over-commit by one unit possible, `debitSaved` clamps at zero (N7) | `CreditAccountant.php:53,204-208` |

---

## 7. Measured-cost plan

### 7.1 What already exists (read-only)

```
php artisan docintel:ai-usage-report --days=30 --limit=10 --json
php artisan docintel:credit-economics-report --days=30 --json
```
Both are read-only, aggregate and metadata-only (`DocumentAiUsageReport` forces a `READ ONLY` transaction). They give spend by day/model/purpose and plan margins; `credit-economics-report` has per-operation quantiles but **only for documents that have an AI quote** (none while the flag is off), plus a "pre-rollout cohort" block that gives cost percentiles for documents without a quote (`CreditEconomicsReport.php:158-168`).

### 7.2 Cost per document by page-count band and route

Run inside the production container (AGENTS.md rule 3: one `railway ssh --service CA_BACKEND --environment production -- ...` at a time; do not paste output containing document names). These commands only SELECT, inside a read-only transaction, and select no names, text or quotes.

**(a) Cost per document by page band and route, last 60 days, Ready documents:**

```
php artisan tinker --execute="$(cat <<'PHP'
DB::transaction(function () {
    DB::statement('SET TRANSACTION READ ONLY');
    $rows = DB::select("
        select
          case when coalesce(d.pages,0) = 0 then 'pages-unknown'
               when d.pages <= 5  then '01-05'
               when d.pages <= 20 then '06-20'
               when d.pages <= 50 then '21-50'
               else '51+' end as page_band,
          coalesce(d.ai_pipeline::jsonb->>'route', 'none') as route,
          d.type,
          count(*) as docs,
          round(percentile_cont(0.5) within group (order by c.cost)::numeric, 4) as usd_p50,
          round(percentile_cont(0.9) within group (order by c.cost)::numeric, 4) as usd_p90,
          round(max(c.cost)::numeric, 4) as usd_max,
          round(avg(c.in_tokens)::numeric, 0) as avg_input_tokens,
          round(avg(c.out_tokens)::numeric, 0) as avg_output_tokens,
          sum(c.unknown_runs) as runs_with_unknown_cost
        from documents d
        join (
          select document_id,
                 sum(estimated_cost_usd) as cost,
                 sum(input_tokens) as in_tokens,
                 sum(output_tokens) as out_tokens,
                 count(*) filter (where estimated_cost_usd is null) as unknown_runs
          from document_ai_runs
          where created_at >= now() - interval '60 days'
            and provider = 'anthropic'
            and purpose not in ('document_qa', 'document_comparison')
          group by document_id
        ) c on c.document_id = d.id
        where d.status = 'Ready' and d.deleted_at is null
        group by 1, 2, 3
        order by 1, 2, 3");
    foreach ($rows as $r) { echo json_encode($r), PHP_EOL; }
});
PHP
)"
```
What it measures: summed Anthropic **estimate** (tokens × configured rate) over **all** runs on the document, including retries and failed attempts, so retry waste is inside the number. It does not include Voyage, Q&A or comparisons. Caveat: `documents.pages` is only set for PDFs (images show `pages-unknown`), and a document processed twice (reprocess) counts both rounds in the last 60 days.

**(b) OCR cost per page (scanned PDFs and images):**

```
php artisan tinker --execute="$(cat <<'PHP'
DB::transaction(function () {
    DB::statement('SET TRANSACTION READ ONLY');
    $rows = DB::select("
        select d.type, count(distinct r.document_id) as docs, count(*) as page_calls,
               round(avg(r.estimated_cost_usd)::numeric, 5) as usd_per_call_avg,
               round(percentile_cont(0.9) within group (order by r.estimated_cost_usd)::numeric, 5) as usd_per_call_p90,
               round(avg(r.input_tokens)::numeric, 0) as avg_in, round(avg(r.output_tokens)::numeric, 0) as avg_out,
               count(*) filter (where r.estimated_cost_usd is null) as unknown
        from document_ai_runs r join documents d on d.id = r.document_id
        where r.purpose = 'ocr' and r.created_at >= now() - interval '60 days'
        group by d.type order by d.type");
    foreach ($rows as $r) { echo json_encode($r), PHP_EOL; }
});
PHP
)"
```

**(c) Chart-vision (visual job) cost and count per document:**

```
php artisan tinker --execute="$(cat <<'PHP'
DB::transaction(function () {
    DB::statement('SET TRANSACTION READ ONLY');
    $rows = DB::select("
        select count(*) as docs, round(avg(n)::numeric, 1) as avg_visual_calls, max(n) as max_visual_calls,
               round(avg(cost)::numeric, 4) as avg_usd, round(max(cost)::numeric, 4) as max_usd
        from (select document_id, count(*) n, sum(estimated_cost_usd) cost from document_ai_runs
              where purpose = 'chart_vision' and created_at >= now() - interval '60 days' group by document_id) v");
    foreach ($rows as $r) { echo json_encode($r), PHP_EOL; }
});
PHP
)"
```

**(d) Does bytes ÷ 3 predict the provider's token count? (validates finding N1)**

```
php artisan tinker --execute="$(cat <<'PHP'
DB::transaction(function () {
    DB::statement('SET TRANSACTION READ ONLY');
    $rows = DB::select("
        select d.type, count(*) as docs,
               round(avg(char_length(d.extracted_text)::numeric / nullif((d.ai_pipeline::jsonb->>'tokens')::numeric, 0)), 2) as chars_per_token_avg,
               round(min(char_length(d.extracted_text)::numeric / nullif((d.ai_pipeline::jsonb->>'tokens')::numeric, 0)), 2) as chars_per_token_min,
               round(max(char_length(d.extracted_text)::numeric / nullif((d.ai_pipeline::jsonb->>'tokens')::numeric, 0)), 2) as chars_per_token_max
        from documents d
        where d.ai_pipeline::jsonb->>'count_method' = 'anthropic' and d.extracted_text is not null and d.deleted_at is null
        group by d.type order by d.type");
    foreach ($rows as $r) { echo json_encode($r), PHP_EOL; }
});
PHP
)"
```
The quote assumes 3 characters per token (`ChunkPlanner.php:73`). Note `count_method='anthropic'` exists only for documents that were large enough to ask the provider (`IncrementalPipeline.php:66`, called only when the local estimate is above `large_tokens`), so small documents are not in this sample.

**(e) Quick shape of the population (sizes, routes), to see what the above is describing:**

```
php artisan tinker --execute="$(cat <<'PHP'
DB::transaction(function () {
    DB::statement('SET TRANSACTION READ ONLY');
    foreach (DB::select("select type, coalesce(ai_pipeline::jsonb->>'route','none') route, count(*) docs,
        round(avg(pages)::numeric,1) avg_pages, max(pages) max_pages
        from documents where status='Ready' and deleted_at is null group by 1,2 order by 1,2") as $r) { echo json_encode($r), PHP_EOL; }
});
PHP
)"
```

These SQL statements and column names (`documents.pages`, `documents.ai_pipeline`, `documents.status`, `deleted_at`, `document_ai_runs.purpose / provider / estimated_cost_usd / input_tokens / output_tokens / document_id / created_at`) were read from the models and migrations; **I could not run them, so a column-name mistake would show up as a SQL error, not as wrong data.** If `documents.ai_pipeline` is `json` rather than `jsonb`, the `::jsonb` casts still work.

### 7.3 What is not measurable today, and the minimal non-billing change (description only; nothing implemented)

| Gap | Why it matters | Minimal change (log-only, no billing effect) |
|---|---|---|
| No per-document cost rollup | every analysis re-sums runs; retries/waste are not separated | At publish (inside `DocumentRevisionPublisher`, after the charge, wrapped in try/catch), write one `Log::info('document cost summary', [...])` with `document_id`, `type`, `pages`, `route`, tokens, run count, sum of `estimated_cost_usd`, count of runs with unknown cost, count of failed/partial runs. Alternatively store the same array in the publish `processing_jobs.output`. No new column, no migration, nothing read by billing |
| Voyage embeddings unmetered | a real cost excluded from every margin | In `VoyageEmbeddingClient`, log the response's reported token usage (if the provider returns it) next to the document id. Needs a check of the provider response; NOT VERIFIED that usage is returned |
| `count_tokens` calls unrecorded | small, but not zero | count calls in the same log line |
| Tesseract/pdftoppm time | CPU cost of location | duration is already in stage records; add `pages_attempted` and wall time in the stage output (the PDF job already stores `pages_attempted`, `pages_skipped`, `pages_failed`, `LocatePdfTextJob`/`PdfTextLocationResult`) |
| Invoice reconciliation | estimates ≠ invoice | monthly manual comparison of `sum(estimated_cost_usd)` with the Anthropic invoice, per model |

---

## 8. Decisions D-1 … D-15

Source: `docs/audits/docintel-ai-credits-independent-review.md:209-227` (PR #83). **None is recorded as decided anywhere in `docs/`** (searched `docs/audits` and `docs/billing-credits.md`; no resolution notes). So all are NOT DECIDED.

| D | Decision | Blocks enabling AI credits? |
|---|---|---|
| D-1 | Whether and when to enable workload billing; transition for Professional (12–32× more per large document than the flat plan) | **Yes** |
| D-2 | Approve the route-prediction fix (N1) and recomputed floors | **Yes**: until fixed, 42k–60k-character documents may be priced 30+ and run on the cheaper route |
| D-3 | Dense policy: by file type or by measured content | No (tune later) |
| D-4 | Keep fixed quote settlement (no usage rebate) | No (current behaviour is the recommendation) |
| D-5 | `needs_review_policy=release`; a failed reanalysis is never settled | No (default is safe) |
| D-6 | OCR pricing (20 + 1/page), confirmation step, and whether reprocessing a pre-rollout scan is charged (N4) | **Yes** for N4 |
| D-7 | Reanalysis price (band, fixed 10, 50%); consistency across routes (N5) | No |
| D-8 | Credit scale (×1 or ×10 headline) and a new `quote_version` before enablement | **Yes**: only free to change while no customer holds AI credits. Also decides "very large ≈ 10 credits" |
| D-9 | Annual allowance (96 / 225), annual price, or accept ~69% / ~67% margins | **Yes** for annual plans |
| D-10 | Free grant 20 or 40 credits | No |
| D-11 | Saved credits during a subscription (`saved_fallback_during_subscription`) | No (default off) |
| D-12 | Legacy periods and grandfathered Starter migration | **Yes** for those customers |
| D-13 | Cumulative unpaid-spend limit per document; whether the provider bills rejected requests (N3) | **Yes** (open exposure R-1) |
| D-14 | Accept the OCR provider-cap floor default 0.05 (already merged in PR #83) | No (merged; confirm) |
| D-15 | Q&A pricing and caps | No (free with caps) |

**New decisions from this audit:** (a) a per-workspace/cohort gate for the flag (none exists); (b) charge, release or retry for a revision with failed chunks (PR #85 report).

---

## 9. Recommendation: smallest safe sequence to "bigger documents cost more"

Do **not** flip `DOCINTEL_AI_CREDITS_ENABLED` as the first step. In order:

1. **Measure (no code).** Run §7.2 (a)–(e) in production. You need: cost p50/p90/max per page band and route; OCR cost per page; chart-vision cost; the real chars-per-token ratio. Also run `credits:audit-settlements` and compare Anthropic's invoice with `sum(estimated_cost_usd)` for the same days. *This decides whether bands 4/10/30/80 and the 0.027 USD ceiling are even in the right region.*
2. **Log-only cost rollup (small PR, no billing effect)** per §7.3, so that every document from then on carries one cost line. Keep it independent of the charge.
3. **Decide D-8** (scale) and the target price of a large document, before anything else, because it is free to change only until someone holds AI credits.
4. **Fix N1 (D-2):** make the quote route and floors match the executed route (and recompute the synthesis floors for the current default model, which have not been recomputed since `claude-sonnet-5-5` became the synthesis default, `docs/audits/ai-credits-lifecycle-trace.md` §12).
5. **Add a cohort gate** (a workspace allow-list read by `QuoteService::enabled()` and `CreditAccountant::mode()`), so the flag can be on for named workspaces only. Today it cannot.
6. **Close the exposure items:** D-13 (cumulative unpaid-spend cap) and the failed-chunk charge rule.
7. **Enable for a small cohort** with a test workspace first; re-run `credits:audit-settlements` and the per-workspace ledger identity after each step; compare charged credits with measured cost for the cohort. Widen only when the margin holds at the p90 of measured cost, not at the ceiling.
8. **Only then** consider larger allowances (the earlier analysis's gated schedule A3) and the annual-plan fix.

The smallest change that gives *some* "bigger costs more" without the full system: none is safe without step 1, because the bands are provisional ("EVERYTHING here is provisional and NOT validated against production cost quantiles", `config/ai_credits.php:3-5`).

---

## 10. What I could not verify

* The production values of `DOCINTEL_AI_CREDITS_ENABLED` and `DOCINTEL_REVISION_V1`, the plans in force, real balances.
* Any real provider cost, token or page distribution; every number from the earlier pricing analysis (margins, n = 5) is carried over unchanged and was not recomputed.
* Whether Anthropic's invoice matches `estimated_cost_usd`, whether `count_tokens` or rejected requests are billed, whether Voyage returns usage.
* That the §7.2 SQL runs unmodified: it was written from migrations and models and not executed.
* Every test cited was read, **not run**.
* Whether the 4/10-credit caps can fund one maximal request on production models (R-2).
* Whether shared-IP or carrier-NAT users are blocked from the trial in practice.
* Whether any workspace has a legacy reservation open at the moment of a flag flip (N7).
