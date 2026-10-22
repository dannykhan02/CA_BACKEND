# Document credits: charge on publish

Supersedes the "settle at Ready" rule described in
[BILLING_SUBSCRIPTIONS.md](BILLING_SUBSCRIPTIONS.md) and
[DOCUMENT_CREDITS.md](DOCUMENT_CREDITS.md), both of which remain accurate as
records of what shipped before 2026-10-20.

## The policy

**A document costs one credit when its revision publishes. Nothing else costs
anything.**

| Outcome | Charge | Reservation |
| --- | --- | --- |
| A revision publishes | 1 credit | settled |
| Failed, Needs Review, cancelled, deleted | 0 | released |
| Retry of an unpublished run | 0 | held across the retry |
| Reprocess of an already-settled document | 0 | not re-reserved |

Publication is the only point at which the customer can read the analysis, so
it is the only point at which they pay for it. An analysis that ends without a
published revision is the platform's problem, not the customer's.

## Where the charge happens

`DocumentRevisionPublisher::publish()` takes the credit, inside the same
transaction and under the same document row lock that commits
`published_intelligence_revision_id`. A publication is therefore never charged
without publishing, and never published without being charged.

`WorkspaceCreditService::accountForReadyDocument()` performs the charge. It
claims `documents.credit_accounted_at` with one conditional update
(`credit_accounted_at IS NULL -> now()`) before moving any balance, so two
concurrent publications, a repeated publication, and a later revision of an
already-settled document all charge exactly once. `publish()` retries on
deadlock, because taking the workspace lock after the document lock lets two
documents of one workspace contend when they publish at the same instant.

### When revisions are disabled

`publish()` runs only when `DOCINTEL_REVISION_V1` is on. It is on in
production. With it off there is no publication to charge for, so the charge
falls to the transition that actually delivers the product — the one that makes
the document readable:

- **Normal route** — the Ready transition in `GenerateInsightsJob`. The
  document becomes readable there; the intelligence batch that follows
  (`document_type`, `entities`, `risks`, `deadlines`, `document_summary`) is
  additive enrichment dispatched separately, not a gate on Ready.
- **Incremental route** — the Ready transition in
  `GenerateDocumentSummaryJob`, which is where that route reaches Ready.
- **Unchanged re-upload** — the skip path in `GenerateInsightsJob`, which is
  terminal and runs no further stage.

**This difference matters.** With revisions on, `deadlines` is a required stage
that `publish()` gates on, so a malformed deadline costs the customer nothing —
the production failure of 2026-10-20, where a document ended with
`intelligence_generation 1`, no published revision and a consumed credit, is
fixed. With revisions off, Ready legitimately precedes the deadline stage, so
that window remains open in that configuration. Turning revisions off is
therefore a billing-behaviour change, not only a reader change.

What changed on 2026-10-20 is narrower than "the charge moved to the end":

- The normal route used to charge at the `ai_analysis` stage *and* publish
  later; now, with revisions on, only publication charges.
- `MergeDocumentEvidenceJob` used to debit in legacy accounting. It passes
  `$ready = false` to mean "merged evidence alone never debits", but the flag
  was honoured only in the AI-credit branch. Merge runs while the document is
  still `Processing`, so under this policy it cannot be a charge point; the
  flag is now honoured in both regimes.

## Releasing a reservation

`EntitlementService::releaseDocumentReservation(Document, $reason)` returns a
reservation without charging. It is idempotent and refuses to touch a settled
document, so it is safe to call on any terminal path. It is called when:

- analysis fails terminally, or has no extracted text
  (`GenerateInsightsJob`);
- synthesis fails after its retries (`GenerateDocumentSummaryJob`);
- a document is deleted (`DocumentController::destroy`).

`billing:release-stale-reservations` remains the hourly backstop for anything
that never reaches one of those paths — a worker killed mid-run, an abandoned
pipeline, a reservation older than `BILLING_PIPELINE_HOURS` (24h). Reading a
workspace's credits performs the same release lazily.

## Retries

`GenerateInsightsJob` and `GenerateDocumentSummaryJob` allow three attempts
with a 60s/300s backoff. The reservation stays held across the attempts and is
released only once they are exhausted. Deterministic failures — schema,
validation, authentication, billing — call `fail()` and never retry, so a bad
response cannot loop at the customer's expense.

## Legacy documents

Documents settled under the old policy keep their charge. They are not
refunded and never re-charged: `credit_accounted_at` is already set, so
`reserveDocument` will not reserve again and `releaseDocumentReservation` is a
no-op. There is deliberately no refund command.

## Operator commands

```
php artisan credits:audit-settlements [--limit=20]
```

Read-only. Reports, with counts and ids:

1. settled documents with no published revision (expected for pre-policy
   documents);
2. documents charged more than once;
3. reservations held longer than 24 hours.

Exits non-zero **only** for (2), which is the one condition that means a real
accounting fault.

```
php artisan billing:release-stale-reservations [--dry-run]
```

Scheduled hourly. `--dry-run` reports the workspaces it would sweep and
changes nothing.

Operator note: run `php artisan credits:audit-settlements` after each deploy of this policy.

## Image text location

Tesseract runs in the worker image. Kill switch: OCR_IMAGE_LOCATION_ENABLED. Backfill: php artisan documents:ocr-locate-images (dry run by default, --force to write).
