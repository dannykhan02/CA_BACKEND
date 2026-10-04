# Document deletion and analysis retry policy

## Deletion

User deletion soft deletes the document. Active reads and upload duplicate checks exclude it. The database unique index applies only when `deleted_at IS NULL`, so the same bytes can be uploaded as a new document with a new ID and a new pipeline. No old extracted text, OCR, chunks, embeddings, AI runs, results, or summary is copied to the new ID. Active duplicates still conflict within the workspace.

The deleted row, its original stored file, versions, OCR results, extracted text, embeddings, AI runs, processing history, and derived intelligence remain retained under the old ID. This is the current storage and derived intelligence retention policy for soft deletes; no asynchronous object deletion is attempted. Relationship rows, comparisons, tracked items, and suggestion dismissals that point at the deleted document are removed by `DocumentObserver` in the deletion transaction. Matter membership is inert because ordinary document queries exclude soft deleted rows. A future permanent erasure workflow must explicitly purge the retained object and child rows while preserving immutable billing, payment, usage, and audit records according to retention requirements.

Deleting a processed document does not refund or regrant its document allowance. Reuploading its bytes creates a new document reservation and consumes its own allowance only when that new document reaches Ready. Audit events for the old and new IDs remain separate.

## Optional analysis retry

The `reprocess` endpoint accepts `intelligence_only: true` and an optional stage from `document_type`, `entities`, `risks`, `deadlines`, or `document_summary`. A named stage must be failed or not started. Omitting `stage` retries all failed or not started optional stages. The document row is locked while pending stage records are inserted; a second request sees pending state and cannot queue the same stage again. Successful stages and their persisted data remain intact. Each extraction job replaces only its own records in a transaction. The summary depends on the four extraction stages and is regenerated once after selected extraction jobs settle. Already accounted documents do not reserve a new document allowance for optional analysis.

This does not provide exactly once Anthropic usage across a worker crash or duplicate queue delivery. Every actual provider call remains in `document_ai_runs`; only completed persisted stages may be skipped by the unchanged document guard. Worker level duplicate delivery and cross stage AI usage accounting need a durable claim/idempotency key if exactly once charging becomes a requirement.
