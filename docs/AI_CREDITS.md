# AI credits (workload billing)

Dormant behind `DOCINTEL_AI_CREDITS_ENABLED` (default `false`). With the flag off, billing is the legacy rule in
[billing-credits.md](billing-credits.md): one document unit when a revision publishes, whatever its size. Everything
below applies only with the flag on. Config: `config/ai_credits.php`. Quote version: `v3`.

## Bands (chosen by token edges)

A document is priced by its tokens. The first band whose `max_tokens` covers it wins (edges are inclusive). A band's provider
spend cap is its credits x USD 0.027. There is no size-based "completion gate" any more: the edges are the price.

| Band | Up to (tokens) | Credits | Provider cap (USD) | Measured cost it must cover (USD) |
|---|---|---|---|---|
| small | 4,000 | 2 | 0.054 | up to 1k tokens 0.016-0.036; 2-4k 0.034-0.051 |
| medium | 8,000 | 6 | 0.162 | (no measurement between 4k and 12k) |
| regular | 18,000 | 10 | 0.27 | ~12k 0.226; 15-18k 0.195-0.228 |
| large | 35,000 | 20 | 0.54 | 27k 0.355 |
| xlarge | 90,000 | 30 | 0.81 | 40-54k PDF 0.657; 77k 0.575-0.677 |
| very_large | 250,000 | 80 | 2.16 | 39k XLSX 0.824 (reached through the dense shift) |
| above | declined (`document_too_large`) | | | |

The measured column is `docintel:cost-vs-size-report` on 24 Ready production documents; the test
`test_no_band_cap_is_below_the_measured_cost_of_a_document_of_that_size` pins that every measured point fits its band's cap.

* A **dense** document (spreadsheet, or mostly numeric or tabular text) moves up one band.
* A band also moves up when its cap cannot fund what the pipeline must reserve: the incremental route's synthesis reservation,
  or a scan's OCR bound. Otherwise it never moves up for size. If the reservation does not fit even the top band the
  document is declined.
* Plan allowances are unchanged: Starter 100, Professional 250 monthly AI credits.

## Tokens

* The **provider's count** (`ai_pipeline.count_method = 'anthropic'`) is used as is whenever it exists.
* Otherwise the **local estimate** (text bytes over a per-type ratio: PDF 3.0, DOCX 5.6, default 3.0) is multiplied by
  `local_estimate_multiplier` (1.15), because it runs about +/-30% off the provider's count. The quote's `preflight` records
  `token_source` (`provider_count`, `local_estimate`, `ocr_pages`), `raw_tokens`, `estimate_multiplier` and `spreadsheet_multiplier`.
* **Spreadsheets** (XLSX) are multiplied by `spreadsheet_token_multiplier` (1.6; provisional, one sample), whichever source the
  count came from, and are dense. XLSX has no ratio of its own in the map so the two corrections are not stacked.
* **Scans** use pages x `ocr.tokens_per_page` (600) when that is more than the text, never below the `small` band.

## Rules

* **Confirmation.** A quote of 30 credits or more (`confirm_from_credits`), or a document of more than 100 pages
  (`confirm_over_pages`), waits for the customer to accept the price before anything is reserved. The quote carries
  `requires_confirmation: true` (`documents.ai_pipeline.credit_quote`, and `pendingConfirmations[]` in
  `GET /api/workspace/credits` as `requiresConfirmation`, with `pages`).
* **Scans.** OCR is not a separate charge. Before any page is read the scan must be payable (balance at least its estimated
  price). The OCR operation only bounds provider spend: pages x USD 0.006 (`ocr.provider_cost_usd_per_page`, provisional: twice
  the highest per-call cost seen in production, about USD 0.003), at least USD 0.05, at most 60 pages.

  | Pages | Tokens | Quote | OCR spend cap |
  |---|---|---|---|
  | 1 | 600 | small, 2 | 0.05 |
  | 2 | 1,200 | small, 2 | 0.05 |
  | 10 | 6,000 | medium, 6 | 0.06 |
  | 60 | 36,000 | xlarge, 30 | 0.36 |
* **Charge.** The quoted credits are debited once, when a revision publishes. Measured provider cost never changes the charge.
* **The cap is enforced while processing.** Every provider request reserves its worst-case cost against the quote's cap before it
  is sent; a request that would take spend past the cap is refused with `budget_exceeded`
  (`OperationSpend::reserveAttempt`, `AnthropicClient::callWithRetry`). On the incremental route the document budget is also
  lowered to the cap. See the PR for what each route then does.
* **No charge for failed or partial work.** A failed or Needs Review document releases its reservation. A revision that
  publishes with failed extraction chunks (`ai_pipeline.coverage.failed_chunks > 0`, which includes chunks stopped by the budget) is
  delivered but not charged: the reservation is released with reason `partial_result`, and a re-analysis is admitted as a
  first analysis.
* **Reprocess** of a document that is already paid for reserves and charges nothing, except the explicit paid re-analysis of
  an incremental-route document (`ai_credits.reanalysis`).

## Cost log and measurement

At each publication one line is logged, `Document cost summary` (ids, type, pages, tokens, band, quoted and settled credits,
provider cost from `document_ai_runs`, run counts, failed chunks), with the flag on or off. Voyage embeddings log their token
usage; `count_tokens` calls were already logged.

```
php artisan docintel:cost-vs-size-report [--days=N] [--summary-only] [--stress=1.25]
```

Read-only. One CSV row per Ready document with priced runs, then per token bucket: count, median, p90 and max cost, the credits
and band the p90 (and p90 x stress) needs at USD 0.027 per credit, and the band the quote gives that size with whether its cap
covers the worst document and p90 x stress. Use it to move the edges.

`claude-haiku-5-5` requests whose total prompt input exceeds 100,000 tokens are priced at the long-context tier
(`document_intelligence.pricing_long_context`) in the estimate and in the hard request bound.

## Env

`DOCINTEL_AI_CREDITS_ENABLED`, `DOCINTEL_AI_CREDITS_QUOTE_VERSION`, `AI_CREDITS_BAND_{SMALL,MEDIUM,REGULAR,LARGE,XLARGE,VERY_LARGE}_{CREDITS,MAX_TOKENS}`,
`AI_CREDITS_LOCAL_ESTIMATE_MULTIPLIER`, `AI_CREDITS_SPREADSHEET_TOKEN_MULTIPLIER`, `AI_CREDITS_CONFIRM_FROM_CREDITS`,
`AI_CREDITS_CONFIRM_OVER_PAGES`, `AI_CREDITS_CPT_{PDF,DOCX,DEFAULT}`, `AI_CREDITS_OCR_{TOKENS_PER_PAGE,MIN_BAND,MAX_PAGES,USD_PER_PAGE,MIN_CAP_USD}`.
Band variables are prefixed `BAND_` on purpose: the earlier layouts used `AI_CREDITS_SIMPLE*`, `AI_CREDITS_STANDARD*`,
`AI_CREDITS_{TINY,SMALL,MEDIUM,LARGE,VERY_LARGE}[_MAX_TOKENS]`, `AI_CREDITS_MIN_COST_*` and `AI_CREDITS_CPT_XLSX`, none of which is read now.

## Rollback

Set `DOCINTEL_AI_CREDITS_ENABLED=false` on **both** `CA_BACKEND` and `ca-horizon-worker` and redeploy or restart both
(the worker reads config at boot; a flag left on in the worker keeps quoting and reserving). Open reservations still
settle or release correctly with the flag off (`accountForReadyDocument` is not gated on the flag), and workspaces return
to one document unit per publication.
