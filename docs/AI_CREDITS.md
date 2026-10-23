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
| xlarge | 90,000 | 35 | 0.945 | 40-54k PDF 0.657; 77k 0.575-0.677 |
| very_large | 250,000 | 80 | 2.16 | 39k XLSX 0.824 (reached through the dense shift) |
| above | declined (`document_too_large`) | | | |

The measured column is `docintel:cost-vs-size-report` on 24 Ready production documents; the test
`test_no_band_cap_is_below_the_measured_cost_of_a_document_of_that_size` pins that every measured point fits its band's cap.

* The **dense rule** (a spreadsheet, or mostly numeric or tabular text, moves up `dense_band_shift` bands) is **off by default**
  (`ai_credits.dense_shift.enabled`). Bands follow token edges only; a spreadsheet's density is already priced by its x1.6 token
  multiplier. The code is kept; set `AI_CREDITS_DENSE_SHIFT_ENABLED=true` to bring it back.
* A band also moves up when its cap cannot fund what the pipeline must reserve: the incremental route's synthesis reservation
  (see below), or a scan's OCR bound. Otherwise it never moves up for size. If the reservation does not fit even the top band
  the document is declined.

### Synthesis reservation (incremental route)

Only a document above `document_intelligence.large_tokens` (14,000 tokens) or `max_extraction_chars` (60,000) takes the
incremental route; a smaller one has no reservation (floor 0). The floor is the worst-case cost of the synthesis the pipeline holds
back from extraction (`AnthropicClient::synthesisReservation`: input bytes counted as tokens, 8,192 output tokens, at the
synthesis model's price). Config: `ai_credits.synthesis_reservation`.

| `mode` | The quote floor and the extraction-time hold |
|---|---|
| `primary_only` (default) | The primary synthesis request alone, times `headroom`. A fallback or a repair is paid from the cap that remains when it is sent. |
| `full` | Primary + one degraded fallback + one repair, as before. Any other value behaves as `full`. |

* **A refused recovery request.** `OperationSpend::reserveAttempt` refuses a request whose worst case does not fit the cap that
  remains (`budget_exceeded`, not degradable). A repair that is refused fails the synthesis; a fallback that does not fit the
  document budget ends the document `Needs Review` with `synthesis = budget_exceeded` and `partial = true`. Either way nothing
  is delivered, so the hold is released (`needs_review_policy = release`) and the customer is not charged.
* **Only with a quote.** `primary_only` applies to the pipeline (`IncrementalPipeline::canReserve`, `SynthesisCheckpoint`) only
  for a document with an open quote, because a fallback is funded "at request time" by that quote's cap. Without one (credits off,
  a legacy workspace) the pipeline still holds all three requests against the document budget.
* **`headroom`** multiplies the primary request's bound in the floor and in the extraction-time hold. It ships at 1.0:
  at 1.25 a 15k-token PDF-density document (primary about USD 0.234, hand-computed) would need 0.293 and be lifted from regular
  (cap 0.27) to large.
* **What this does not change.** Request admission is still the worst case of each request (`ProviderRequestAdmission`), added to
  what was already spent. See the PR for why a document can still be refused at request time inside a band whose cap covers its
  measured total cost.

Quote with the incremental route on, provider token counts, PDF density (pinned by `RealisticSynthesisReservationTest`):

| Tokens | Route | Band | Credits | Cap (USD) | Measured cost it must cover |
|---|---|---|---|---|---|
| 300 | normal | small | 2 | 0.054 | 0.036 |
| 3,400 | normal | small | 2 | 0.054 | 0.051 |
| 15,000 | incremental | regular | 10 | 0.27 | 0.228 |
| 27,000 | incremental | large | 20 | 0.54 | 0.355 |
| 54,000 | incremental | xlarge | 35 | 0.945 | 0.657 |
| 77,000 | incremental | xlarge | 35 | 0.945 | 0.677 |

With `mode = full` the same 15k document is quoted large (20 credits).
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
php artisan docintel:cost-vs-size-report [--days=N] [--summary-only] [--skip-would-quote] [--stress=1.25] [--stage-limits]
```

Read-only; writes nothing and creates no quote, reservation or ledger row (the would-quote part runs inside a transaction that is
always rolled back, and a test asserts nothing is saved). One CSV row per Ready document with priced runs:

* **Size and cost:** tokens as the quote would count them (`quote_tokens`, `local_estimate_tokens`, `provider_tokens`), route, OCR pages,
  total cost, calls, first-try cost (retries and failed runs are the difference).
* **Cost by stage:** `stage_extraction_usd`, `stage_synthesis_usd`, `stage_ocr_usd`, `stage_chart_vision_usd`, `stage_other_usd`
  (extraction and synthesis follow `document_intelligence.model_roles`), `largest_request_usd` and its purpose, and a
  `cost_<purpose>` and `model_<purpose>` pair for every real purpose value that occurs (the models used for it, `|`-joined).
* **Would quote:** what `QuoteService::previewDocument` returns today: `would_tokens`, `token_source`, `would_band`, `would_credits`,
  `would_cap_usd`, `synthesis_floor_usd`, `would_route` (the route the quote assumes; `route` is the one that ran).
* **Incremental-route documents only:** `reservation_floor_usd` (the pipeline's synthesis reservation computed now, whatever the quote
  assumed), `real_synthesis_cost_usd` and `floor_over_real`.

`--stage-limits` (also printed after the summary by default) adds a table per purpose and model over successful runs: count,
output-token p50 / p95 / p99 / max, cost p99 and max per request, the share of runs that stopped at `max_tokens`, the configured
`max_tokens`, the realistic output figure, and the worst-case and realistic cost admission would assign to the median run's input.
Use it to set `stage_realistic_output_tokens`.

Then a summary per token bucket (0-1k, 1-2k, 2-4k, 4-6k, 6-10k, 10-20k, 20-30k, 30-60k, 60k+): documents, median / p90 / max cost, the credits
and band the p90 (and p90 x stress) needs at USD 0.027 per credit, the band the quote gives that size and whether its cap covers the
worst document and p90 x stress, and **where the quote overcharges**: `median_would_credits`, `median_would_cap_over_cost` (the quote's
cap divided by what the document cost) and `would_minus_needed_x1.25` (credits charged minus credits the p90 x 1.25 needs; positive =
the quote charges more than the cost needs). `--skip-would-quote` leaves the would-quote columns empty and is much faster, because the
would-quote reads the text of every document. Use it to move the edges.

`claude-haiku-5-5` requests whose total prompt input exceeds 100,000 tokens are priced at the long-context tier
(`document_intelligence.pricing_long_context`) in the estimate and in the hard request bound.

## Admission against the cap

`ai_credits.admission_mode`: `realistic` (default) admits a provider request when what is already spent plus its **realistic** cost
(the request's input bound at its price plus `stage_realistic_output_tokens[purpose]` output tokens, never above its `max_tokens`) fits
the quote's cap, **and** its worst case (every `max_tokens` token) fits `overrun_factor` x the cap. `strict` admits only when spent plus
the worst case fits the cap (the earlier rule). A purpose with no realistic figure is admitted at its worst case. A refusal is
`budget_exceeded`: the document ends Needs Review (partial), the hold is released and the customer is not charged. The input bound still
counts each byte as a token. A small-band Sonnet 5.5 summary with 4,096 `max_tokens` and about 10 kB of input: realistic about 0.045,
worst case about 0.061, small cap 0.054.

## Switching on and off

* **On:** set `DOCINTEL_AI_CREDITS_ENABLED=true` on **both** `CA_BACKEND` and `ca-horizon-worker` and restart both (the worker reads config
  at boot). Nothing is quoted, reserved or charged until it is on everywhere; with it off the legacy one-unit rule applies.
* **Off (rollback):** set it to `false` on both and restart both. See Rollback below.

## Config keys added since v3

| Key (env) | Default |
|---|---|
| `ai_credits.synthesis_reservation.mode` (`AI_CREDITS_SYNTHESIS_RESERVATION_MODE`) | `primary_only` (`full` = primary + fallback + repair) |
| `ai_credits.synthesis_reservation.headroom` (`AI_CREDITS_SYNTHESIS_RESERVATION_HEADROOM`) | `1.0` |
| `ai_credits.dense_shift.enabled` (`AI_CREDITS_DENSE_SHIFT_ENABLED`) | `false` |
| `ai_credits.admission_mode` (`AI_CREDITS_ADMISSION_MODE`) | `realistic` (`strict` = old rule) |
| `ai_credits.overrun_factor` (`AI_CREDITS_OVERRUN_FACTOR`) | `3.0` |
| `ai_credits.stage_realistic_output_tokens.document_summary` / `.insights` (`AI_CREDITS_REALISTIC_OUTPUT_SUMMARY` / `_INSIGHTS`) | `2500` / `2500` |
| `ai_credits.stage_realistic_output_tokens.{entities,risks,deadlines,document_type,context_resolution,kpi_identity,ocr,chart_vision}` (`AI_CREDITS_REALISTIC_OUTPUT_EXTRACTION`) | `2000` |

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
