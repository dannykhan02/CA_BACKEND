# DocIntel deterministic hardening — Phase 2A boundary and runtime

Date: 2026-10-11. Base: `main` at `3082b4b`. This report covers local code and tests only. No paid provider call, deployment, push, merge, schema migration, grounding change, or evidence deletion occurred. The Phase 1 audit remains an untracked input document.

## 1. Current-state trace


Before editing, the working tree contained only the untracked Phase 1 audit. B2 verifier version was `3`; `max_rejected_claims` was `0`. `AnthropicClient` logged an unapproved model and proceeded. Upload size was 20 MiB; DOCX had an expanded-size loop but no common archive preflight; XLSX loaded a workbook and used calculated values. PDF parsing had no cheap page-count gate. `ScanUploadedFileJob`, extraction/OCR jobs, Stage A chunk jobs and B2 had separate state checks. B2 is dispatched asynchronously after Ready; Brief reads use stored B1/B2 and do not call a provider. Credit accounting already used quote/reservation records and idempotent ledger operations.

## 2. Implemented controls

| Boundary | Control | Enforcement point |
|---|---|---|
| Paid Anthropic messages | Task-to-role model selection, explicit approved model, known pricing/capabilities, output/context/timeout/retry configuration, conservative request estimate, call/document cap | `ProviderRequestAdmission`, called by `AnthropicClient::callWithRetry` on every attempt |
| Token-count endpoint | Approved, priced extraction model required before HTTP | `AnthropicClient::countTokens` |
| Credits | Existing quote admission checked again on each message attempt | `OperationSpend::assertCallAllowed` |
| Document state | Failed/Needs Review reject scan, extract, OCR, visual, intelligence and B2; Ready only for visual/B2; Processing for scan/extract/OCR | `DocumentStagePolicy`, entry jobs and message boundary |
| ZIP/DOCX/XLSX | Archive integrity and metadata ceilings before XML/workbook expansion | `ArchivePreflight` |
| PDF | File size, `pdfinfo` timeout, encrypted/malformed and page-count check before PDF parser/rasterizer | `DocumentTextExtractor`, `PdfRasterizer` |
| XLSX | Sheet/row/cell preflight; formula evaluation removed; cached formula value treated as source data | `SpreadsheetTextExtractor` |
| Images/OCR | Pixel ceiling before visual provider; OCR batch ceiling before reservation | `ClaudeVisionOcrProvider`, `PdfRasterizer`, `OcrPageBatchJob` |
| Errors/logs | Generic user/stage messages for extraction and AI job failures; provider error logs retain shaped metadata without provider-authored error text | Jobs, `AnthropicClient` |

Document text remains in user-role message content. Application-controlled model, system prompt, output schema, retries, limits and credentials are supplied separately by the client, not read from document text. This is a boundary guard, not a complete prompt-injection defense.

## 3. Provider admission and failures

Unknown task/model gives `unsupported_model`; missing or invalid price gives `unknown_price`; invalid limits give `invalid_provider_config`; impossible context gives `context_overflow`; excessive estimated call cost gives `budget_exceeded`. These are local `AiProcessingException` reasons, before the paid message HTTP request. No alternate model is selected. The existing transport taxonomy retains authentication, billing/quota, timeout/transient, malformed output, truncation and refusal handling; retries remain capped and re-enter the same admission path.

The estimate uses text byte length as a conservative token bound, a configured image reserve and configured input/output prices. It checks one request against the call cap and document budget. `OperationSpend` checks the existing quote cap, but its current admission only refuses when **remaining cost is zero or below**. It does not reserve the estimated next call cost atomically against the remainder. Concurrent in-flight calls can therefore overshoot a quote cap. This remains a cost risk; resolving it requires a bounded spend reservation design and concurrency tests, not a claim of complete budget safety here. Request identity is present in the existing quote/chunk/run paths, but a universal per-attempt idempotency key is not yet enforced by the common client.

## 4. Hostile-file limits and explicit failure

All new ceilings are in `config/document_processing.php` and validated at feature admission. Defaults: upload 20 MiB; ZIP 10,000 entries, 200 MiB summed expansion, 100 MiB per entry, ratio 1,000; PDF 1,000 pages and 15-second `pdfinfo` timeout; XLSX 100 sheets, 100,000 rows per sheet and 500,000 cells total; image 100 million pixels; OCR batch at most three pages. The upload ceiling must be positive, and the ZIP expansion ceiling cannot be below upload size. These defaults preserve the existing 20 MiB upload and 200 MiB DOCX expanded-size scale.

Malformed/encrypted PDF, invalid ZIP, over-limit workbook/archive/image and invalid policy fail locally. The extraction job records a stable Failed status and generic error, reaches progress 100 on handled parser failure, releases stale reservations through the existing idempotent service and does not dispatch dependent analysis. Formula text is not evaluated or emitted; only a stored cached value is read. The preflight does not prove that XML expansion, PDF parsing or rasterization is safe under every malformed payload. The underlying parser still needs a process-level memory/time isolation review. Workbook external-link non-fetching is inferred from the local reader path; it is not established by an adversarial network fixture in this slice.

## 5. Terminal-state and late-job policy

Persisted document states remain unchanged. `Failed` and `Needs Review` are terminal for normal automatic processing. `Processing` admits scan/extract/OCR; `Ready` admits visual and B2. Existing Stage A chunk states include pending, queued, running, completed, failed, uncertain, budget, split and superseded. Existing chunk claim/dispatch tokens and merge recovery govern explicit resumability; this change does not reinterpret them. A queued early job that wakes after terminalization returns before reserving or dispatching; the paid-message boundary rechecks the fresh document on each retry. B2 remains additive and falls back to B1.

`Needs Review` has an existing explicit merge-recovery exception that can move a reviewed merge unit back to Processing. This is not a general automatic retry. State transitions are still spread across some jobs; there is no single transactional transition owner for all document and chunk states. A worker already inside parsing may finish local work after another worker terminalizes the document, though the provider boundary blocks a later paid message. Reservation settlement is handled by existing idempotent services; this slice did not add a direct adversarial exactly-once finalization test.

## 6. Reads and regression behavior

No read path was changed. Existing Brief GET/reuse tests passed: reads create no new AI runs or provider requests, rejected B2 stays hidden, and B1 fallback remains. B2 verifier remains `3`; B1 version, prompts, contracts, extraction version and claim-grounding semantics were not changed. F28/F35/F44 and F03/F19 coverage remained in the full passing suite. No semantic-policy version was introduced because this slice did not change evidence interpretation.

## 7. Privacy and artifact inventory

Provider error logs now omit provider-authored `error.message`, which may echo source text. Touched jobs record metadata and generic stage/user messages rather than raw third-party exceptions. No document text, prompt or raw response was deliberately added to new production logs. This is a path-specific safeguard, not an exhaustive logging audit across all application code.

The tracked-file inventory is in `deterministic-hardening-phase2a-artifact-inventory.tsv`: 307 files, 83 `KEEP`, 224 `REVIEW_PROVENANCE`, zero automatically removed. Filename/content-pattern heuristics flag 54 as possible raw source/provider-response artifacts and three test-result logs for possible secret-pattern review. Flags are not proof of customer origin, consent status or secret exposure. Provenance and authority to retain/share these artifacts require human review before any deletion or publication. `.gitignore` now excludes generated task/diagnostic cache and temporary directories; authoritative fixtures remain tracked.

## 8. Configuration

Added `DOCINTEL_MAX_PROVIDER_CALL_USD`, `DOCINTEL_VISUAL_INPUT_TOKEN_RESERVE`, archive/PDF/XLSX/image/OCR ceilings under `DOC_*`, and task-role mapping in `config/document_intelligence.php`. Models, pricing, budgets and limits remain configuration. Admission rejects invalid active limits and unknown model pricing. The existing pricing table still contains unapproved historical models; this is tolerated as dormant configuration, while a request for one is rejected. Operational values should be tuned from legitimate file and call distributions before production rollout.

## 9. Safety and usefulness metrics

| Measure | Observed local result | Scope / remaining limit |
|---|---|---|
| Unauthorized or unpriced message request | Focused fake-HTTP tests: 0 sent | Common Anthropic message boundary; no live provider request |
| Over-call-cap request | Focused fake-HTTP test: 0 sent | Cumulative concurrent quote overshoot remains possible |
| Paid message after terminal state | Terminal-state test: 0 sent | Fresh state checked at boundary; cross-worker transition race before local processing remains |
| Hostile file reaches expensive parsing | Malformed, over-entry, over-expansion, over-cell fixtures rejected in focused tests | Parser memory/time isolation and external-link adversary remain unmeasured |
| Raw content in touched provider error log | Diagnostic tests assert provider-authored error message absent | Other historical logs and artifacts need separate review |
| Valid supported file falsely rejected | Small PDF, DOCX and XLSX fixtures pass | No representative large-file corpus tested; conservative text-byte estimate may refuse near-cap valid calls |
| Valid approved request falsely blocked | Approved-model fixture passes | Real provider request distribution not measured |
| Resumable job falsely terminalized | Focused Ready and existing merge/replay tests pass | No production replay dataset for state transitions |

## 10. Verification

- Focused Phase 2A suite: 15 tests, 56 assertions, passed.
- Focused structured-output fixture repair: 13 tests, 46 assertions, passed. The initial full run exposed six fixtures that did not declare an approved task/model; fixture-only updates aligned them with the new admission contract.
- Existing focused Intelligence/Incremental suite: 121 tests, 836 assertions, passed.
- Final full backend suite: **1,006 tests, 7,379 assertions, passed** using isolated local PostgreSQL at port 55433; provider HTTP was faked in relevant tests. The first full run failed on the six obsolete fixtures; the final run passed.
- Pint run on all changed PHP files: passed, with formatting fixes applied.
- `git diff --check`: passed.

## 11. Remaining risks and recommended next phase

This phase is **partial** against the complete requested safety contract. The main remaining Phase 2A work is atomic estimated-cost reservation across concurrent calls, a universal request identity/idempotency contract where the provider supports it, process-level parser isolation, adversarial XLSX external-link/formula and ZIP/XML expansion fixtures, and direct exactly-once credit/late-callback tests. Resolve those before calling the boundary fully hardened. Then proceed to the separate Phase 2 semantic core from the Phase 1 audit; do not change B1/B2 grounding as part of the remaining runtime work.
