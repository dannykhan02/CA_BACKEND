# Regression Scenario — P1 Bug-Fix Verification

**Status:** Defined 2026-08-29. This document did not exist prior to this
date — earlier references to "the original 6-step regression scenario" in
project notes and `Implementation_Action_Plan.docx` referred to a scenario
that was never actually written down anywhere in this repository. This is
the first real, checked-in definition.

## What this proves

This scenario exists to verify the three P1 bugs from the original bug
report together, end to end, against one real document — not as three
isolated checks that could each individually pass while the pipeline as a
whole is still broken:

- **P1-1** — upload-size mismatch (2MB cap vs. configured 20MB)
- **P1-2** — missing `workspace_settings` silently disabling OCR
- **P1-3** — Horizon worker timeout killing jobs before their own declared
  timeout

## Precondition

- One real document that genuinely requires OCR (scanned or image-based —
  **not** a fast native-text PDF, and **not** a seed/fixture row with a
  null `file_path` or `workspace_id`; both have caused false test results
  in prior sessions — see the audit's Part IV, Section 11).
- The target workspace's `workspace_settings` row confirmed to have
  `ocr_enabled = true` **before** the test starts.

## Steps

Each step gates the next. If a step fails, stop — do not proceed to later
steps and do not count them as passed by omission.

### 1. Confirm OCR is on for the target workspace, before uploading

```bash
php artisan env:info
# or, until env:info exists:
php artisan tinker --execute="
  \$w = App\Models\Workspace::find('<workspace-uuid>');
  echo \$w->settings->ocr_enabled ? 'OCR ON' : 'OCR OFF';
"
```

**Proves:** P1-2. Rules out "OCR silently disabled" as a confound before
the test even starts.

### 2. Upload the document

```bash
curl -i -s -X POST "$BASE_URL/api/documents" \
  -H "Authorization: Bearer $TOKEN" \
  -F "file=@<real-ocr-required-document>" \
  -F "classification=Internal"
```

**Proves:** P1-1. Expect `202 Accepted`, not a rejection at the upload
boundary.

### 3. Poll `processing_jobs` until the OCR/extract stage completes

```sql
SELECT stage, status, started_at, completed_at,
       EXTRACT(EPOCH FROM (completed_at - started_at)) AS duration_seconds
FROM processing_jobs
WHERE document_id = '<document-id>'
ORDER BY started_at;
```

**Proves:** P1-3. The `ocr_check`/`extract` stage should take meaningfully
longer than 60 seconds (the old, too-low ceiling) but complete **before**
150 seconds (the real `supervisor-extraction` timeout in
`config/horizon.php`).

### 4. Confirm the document reached a real terminal success state

```bash
curl -s "$BASE_URL/api/documents/<document-id>" \
  -H "Authorization: Bearer $TOKEN" | jq '.status, .errorMessage'
```

**Proves:** steps 1–3 produced usable output, not just "didn't time out."
Expect `status: "Ready"` or `"Needs Review"`, `errorMessage: null`, and a
non-empty `extracted_text` (check via tinker/DB directly — not exposed on
this resource).

### 5. Confirm the downstream AI batch actually ran

```sql
SELECT count(*) FROM document_kpis WHERE document_id = '<document-id>';
SELECT count(*) FROM document_entities WHERE document_id = '<document-id>';
SELECT count(*) FROM document_risks WHERE document_id = '<document-id>';
SELECT count(*) FROM document_deadlines WHERE document_id = '<document-id>';
```

**Proves:** the pipeline didn't silently stop after extraction. A
slow-but-successful OCR job with nothing downstream is not a pass.

### 6. Ask a real question about the document's content

```bash
curl -s -X POST "$BASE_URL/api/documents/query" \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"question":"<a question whose answer only exists in this document>"}'
```

**Proves:** the full chain is intact end to end — embeddings were
generated from the OCR'd text, retrieval found the right chunk, and the
answer cites the real document. Expect `success: true` and a
non-`"none"` confidence with `cited_document_ids` containing this
document's id.

## Result log

Record each real run here — do not overwrite prior runs, append below.

| Date | Environment | Document used | Step reached | Result | Notes |
|------|-------------|----------------|---------------|--------|-------|
| 2026-08-30 | Local (127.0.0.1:5433) | No 18 (1).pdf, 18 pages, re-uploaded as 01a0511c-9de3-72e6-b874-3c032841bc97 after soft-deleting a stale pre-ocr_enabled Failed row | 3 — FAIL | Blocked | Step 1 confirmed ocr_enabled=1. Step 2 upload succeeded (202). Step 3 failed: ocr_check stage hit TimeoutExceededException — ExtractDocumentTextJob's 120s job timeout fired mid-loop while OCR'ing this 18-page document via ClaudeVisionOcrProvider, which calls Anthropic sequentially once per rasterized page inside the same job with no per-page checkpointing. Document landed on status=Needs Review, error_message="OCR could not process this scanned document." Also observed: failed_jobs got a duplicate-uuid insert attempt (unique constraint violation) on the same failure — separate minor bug, not yet investigated. Root cause: job/Horizon timeout budget was sized for a single AI call, not a page-count-dependent loop — will fail on any real multi-page scanned document, not just this one. Fix approach pending decision (batch pages into chained jobs vs. proportional timeout vs. one-job-per-page) before re-run. |

## Relationship to automated testing

Per `Implementation_Action_Plan.docx` P4, this scenario should eventually
be encoded as a Laravel Feature test
(`tests/Feature/RegressionScenarioTest.php` or folded into
`DocumentUploadTest.php`) so `php artisan test` can run it automatically —
this document is the spec that test should implement, not a replacement
for having one.| 2026-08-31 | Local (127.0.0.1:5433) | No 18 (1).pdf, 18 pages, re-run against 01a0511c-9de3-72e6-b874-3c032841bc97 after P1 fix (OCR batching) | 5 — PASS, Step 6 blocked | Pass (Steps 1-5) / Separate defect found (Step 6) | Steps 1-5 passed cleanly: OCR batched into 6 chained jobs of 3 pages each (batch size reduced from an initial 4 after one batch exceeded the 90s per-batch timeout during testing), all completed within timeout (worst case 56s), 18/18 OcrResult rows with no duplicates, extracted_text reconstructed correctly (17 form-feed separators for 18 pages), document reached Ready with full downstream AI batch complete. Two bugs found and fixed during this work: (1) initial implementation used appendToChain() instead of prependToChain(), which silently dropped all OCR batch jobs to the end of the chain past GenerateInsightsJob, causing an immediate false failure with no OCR ever attempted; (2) OcrPageBatchJob did not clear its own page-number range before writing OcrResult rows, so a Horizon-timeout-triggered retry (observed once, at the original 4-page batch size) duplicated rows for retried pages — fixed by delete-before-insert on the batch's own page range, matching the pattern already used by GenerateInsightsJob/GenerateEmbeddingsJob. Step 6 (document Q&A) failed with ModelNotFoundException on AiPrompt::active('document_qa') — confirmed pre-existing and unrelated to the OCR fix (identical error present in laravel.log from 2026-08-26, four days before this work began); logged as a new, separate defect in Implementation_Action_Plan.docx rather than blocking this fix's verification. |
| 2026-08-31 | Local (127.0.0.1:5433) | Same document (01a0511c-9de3-72e6-b874-3c032841bc97), Step 6 re-run after P1-4 fix | 6 — PASS | Full pass | Root cause of the prior Step 6 block found: database/seeders/DocumentQaPromptSeeder.php already existed with a complete, correctly-framed document_qa prompt template (untrusted-context framing, JSON-only response contract, hallucination-guard instructions matching the other 5 prompt seeders) and was already listed in DatabaseSeeder.php — it had simply never been executed against this local DB. Ran `php artisan db:seed --class=DocumentQaPromptSeeder --force`; confirmed an active document_qa v1 row now exists. Re-ran Step 6 against the real document with a question grounded in known page content ("What departmental awards criteria are discussed in this document?"): success:true, confidence:strong, cited_document_ids correctly contains 01a0511c-9de3-72e6-b874-3c032841bc97, and the returned answer's specifics (Customer Service Excellence/Operational Efficiency/Innovation/Team Collaboration categories, 90/10 mark split, 25/25/25 nomination scoring) match the document's real extracted content. All 6 steps of this scenario now pass end to end. |
