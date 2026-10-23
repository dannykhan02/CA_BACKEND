# AI credits (workload billing)

Dormant behind `DOCINTEL_AI_CREDITS_ENABLED` (default `false`). With the flag off, billing is the legacy rule in
[billing-credits.md](billing-credits.md): one document unit when a revision publishes, whatever its size. Everything
below applies only with the flag on. Config: `config/ai_credits.php`.

## Bands

A document is priced by its estimated tokens. The first band whose `max_tokens` covers it wins.

| Band | Up to (tokens) | Credits | Provider cap (USD, credits x 0.027) |
|---|---|---|---|
| tiny | 2,000 | 1 | 0.027 |
| small | 6,000 | 3 | 0.081 |
| medium | 30,000 | 10 | 0.27 |
| large | 90,000 | 30 | 0.81 |
| very_large | 250,000 | 80 | 2.16 |
| above | declined (`document_too_large`) | | |

* A **dense** document (spreadsheet, or mostly numeric or tabular text) moves up one band.
* A band moves up again while its provider cap is below the minimum useful completion
  (`min_completion_cost_usd`: USD 0.05 plus 0.006 per 1,000 tokens, or the pipeline's synthesis reservation when larger).
  **With today's gate `tiny` is never selected**: 1 credit is USD 0.027 and the gate asks for at least USD 0.05, so a tiny
  document quotes as small. The band exists so that lowering the gate (after measuring real cost) makes it reachable
  without a code change.
* Plan allowances are unchanged: Starter 100, Professional 250 monthly AI credits.

## Rules

* **Confirmation.** A quote of 10 credits or more, or a document of more than 100 pages, waits for the customer to accept
  the price before anything is reserved (`confirm_from_credits`, `confirm_over_pages`). The quote carries
  `requires_confirmation: true` (`documents.ai_pipeline.credit_quote`, and `pendingConfirmations[]` in
  `GET /api/workspace/credits` as `requiresConfirmation`, with `pages`).
* **Scans** are priced by the same bands. OCR is not a separate charge. Before any page is read, the scan must be payable
  (balance at least the estimated price: `ocr.tokens_per_page` = 600 per page, never below `ocr.min_band` = small). The
  OCR operation only bounds provider spend (pages x USD 0.02, at least USD 0.05; at most 60 pages). After OCR the document
  is priced from `max(text estimate, pages x 600)` and its band must also cover the OCR bound, so a scan never prices
  below its OCR cost (a 2-page scan is therefore medium, 10 credits).
* **Token estimate** (`chars_per_token`): PDF 3.0, XLSX 1.7, DOCX 5.6, anything else 3.0. The provider's own count is
  used whenever the pipeline already has it.
* **Charge.** The quoted credits are debited once, when a revision publishes (`DocumentRevisionPublisher`). Measured
  provider cost never changes the charge; the cap only stops spend (a document that cannot finish inside its cap fails
  and is released).
* **No charge for failed or partial work.** A failed or Needs Review document releases its reservation. A revision that
  publishes with failed extraction chunks (`ai_pipeline.coverage.failed_chunks > 0`) is delivered but not charged: the
  reservation is released with reason `partial_result`. A re-analysis of that document is admitted as a first analysis,
  so retrying it is the only charge.
* **Reprocess** of a document that is already paid for reserves and charges nothing. The exception is the explicit paid
  re-analysis of an incremental-route document (`reanalysis` quote, `ai_credits.reanalysis`), which is unchanged.

## Cost log

At each publication one line is logged, `Document cost summary`: document id, file type, pages, estimated tokens, band,
quoted and settled credits, provider cost summed from `document_ai_runs`, run counts, tokens, failed chunks. It is written
with the flag on or off, never writes billing state, and cannot fail a publication. Voyage embeddings log their reported
token usage (`Voyage embeddings usage`); `count_tokens` calls were already logged (`purpose=token_count`).

The estimator prices `claude-haiku-5-5` requests whose total prompt input exceeds 100,000 tokens at the long-context
tier (`document_intelligence.pricing_long_context`), for both the cost estimate and the hard request bound.

## Env

`DOCINTEL_AI_CREDITS_ENABLED`, `DOCINTEL_AI_CREDITS_QUOTE_VERSION` (now `v2`), `AI_CREDITS_{TINY,SMALL,MEDIUM,LARGE,VERY_LARGE}`
and `..._MAX_TOKENS`, `AI_CREDITS_CONFIRM_FROM_CREDITS`, `AI_CREDITS_CONFIRM_OVER_PAGES`, `AI_CREDITS_CPT_{PDF,XLSX,DOCX,DEFAULT}`,
`AI_CREDITS_OCR_{TOKENS_PER_PAGE,MIN_BAND,MAX_PAGES,USD_PER_PAGE,MIN_CAP_USD}`. The old `AI_CREDITS_SIMPLE*`, `AI_CREDITS_STANDARD*`,
`AI_CREDITS_OCR_SURCHARGE`, `AI_CREDITS_OCR_INCLUDED_PAGES`, `AI_CREDITS_OCR_EXTRA_PER_PAGE` and `AI_CREDITS_CONFIRM_FROM_BAND` are no longer read.

## Rollback

Set `DOCINTEL_AI_CREDITS_ENABLED=false` on **both** `CA_BACKEND` and `ca-horizon-worker` and redeploy or restart both
(the worker reads config at boot; a flag left on in the worker keeps quoting and reserving). Open reservations still
settle or release correctly with the flag off (`accountForReadyDocument` is not gated on the flag), and workspaces return
to one document unit per publication.
