# Large document audit — 2026-10-04 (before implementation)

## Current flow and files

`DocumentUploadController` authorizes upload, hashes within workspace, stores through `DocumentStorageService`, reserves entitlement, audits, and chains scan → text → insights → visuals → Voyage embeddings. `ScanUploadedFileJob` preserves ClamAV CLI checks and fails closed when enabled but unavailable. `ExtractDocumentTextJob` also dispatches a separate batch (type, entities, risks, deadlines), whose finally callback schedules summary. Thus summary can precede KPIs from the upload chain.

`DocumentTextExtractor` uses Smalot for PDF text and separately reparses for page count; native text lacks explicit guaranteed page separators. DOCX uses bounded ZIP/WordprocessingML parsing with external entities disabled (avoids PhpWord EMF failures). `SpreadsheetTextExtractor` loads PhpSpreadsheet and materializes sheets as arrays. Empty native text invokes workspace-configured Tesseract/Claude OCR. `PdfRasterizer` creates local page images, then chained `OcrPageBatchJob` jobs process three pages each. OCR deletes prior rows in its page range before retries; worker-local paths do not survive a move to another host. OCR finalizes persistent text with form-feed page separators. Extraction/visual/scanning jobs download whole files to temporary files; normal cleanup exists, but hard worker termination bypasses finally. No new parser is needed.

`AnthropicClient` is the only Anthropic HTTP endpoint implementation: Laravel HTTP, API version 2023-06-01, `/v1/messages`. Public calls: insights/KPIs/charts, document type, entities, risks, deadlines (including obligations), summary, QA, comparison, OCR, chart vision, and optional KPI identity adjudication. `PromptManager` resolves active DB `AiPrompt` versions; seeders install historical prompt revisions, recent migrations install some. `ResponseValidator` validates business shape and summary/QA/comparison references. Existing “structured output” means JSON parsing/validation, NOT provider JSON-schema constraints.

`GenerateInsightsJob` owns Ready transition and `WorkspaceCreditService::accountForReadyDocument`; preserve these semantics. `GuardsDocumentIntelligence`, `PipelineStageRecorder`, `ProcessingStageReconciler`, and `SkipsUnchangedDocuments` provide lifecycle, stale-attempt handling and completed-file-hash skipping. Existing uncommitted changes in these files and lifecycle tests are retained. `DocumentAiRun` stores document/workspace/model/prompt/input/output/status/stop reason/file hash. It lacks per-request retry, duration, cost/cache and checkpoint identity.

`DocumentIntelligenceSummaryResource` serves executive summary/assessment, findings, trends, tensions and questions. Source IDs are `entity:ID`, `risk:ID`, `deadline:ID`, `kpi:ID`; `EvidencePageLocator` only assigns unambiguous exact excerpts to known form-feed pages. `DocumentContextRetriever` retrieves Voyage/pgvector chunks with workspace/ownership/classification filters for QA. Matter intelligence aggregates existing entities/risks/deadlines; obligations are deadlines, not a separate table. Comparison uses persisted intelligence plus selected text. Preserve these integrations and existing source IDs.

## Models and configuration

Single existing `ANTHROPIC_API_KEY`. Default `ANTHROPIC_MODEL=claude-haiku-4-5-20251001` for every task. `ANTHROPIC_MAX_TOKENS` 4096; structured ceiling 8192; general timeout 60s; entity timeout 120s/connect 10s. `document_processing.php` limits input to 60,000 characters; bounded insights output (12 KPIs, 3 charts, 8 points, 5 insights). API local throttle 40 requests/minute; general transport retries up to 4 with sleeps; entity transport one attempt; JSON correction at most once, truncation doubles output allowance.

Horizon extraction queue has TWO workers in local/production; worker timeout 360s, Redis retry_after at least 390s. Individual jobs mostly tries=2, entity timeout=330, summary=60, OCR=90, insights/visuals=120. Job catch/fail frequently prevents queue retries; hard timeouts can still cause redelivery. Failed jobs and processing stages are separate records. No pipeline chunk checkpoints or atomic provider claims exist.

## Existing solutions to reuse

Workspace-scoped upload uniqueness; persistent extracted text; OCR page records; source validation; structured-response diagnostics; prompt versions; AI run records; stage histories; canonical KPI definitions/aliases/profile compatibility; credit ledger/reservations; optional intelligence API and retry controls; embeddings/retrieval; frontend Ready/Processing/Needs Review/Failed and independent failed-stage notices. No rolling summaries are present.

## Confirmed code failure modes

* Every text prompt silently truncates at 60,000 characters: later report evidence omitted.
* Summary truncates serialized JSON mid-object; source eligibility uses substring search.
* Optional finding/trend/tension/question validation throws on one bad item, discarding siblings.
* Entity output can truncate despite doubled output ceiling; no offending-input split.
* Summary and insights race because separate orchestration trees.
* Visual detection accepts every PDF page containing any image; one job loops over all calls, persists only at the end, and may exceed its timeout.
* Hash skip checks do not atomically claim work, ignore prompt changes, and cannot resume partial extraction.
* AI errors log a response preview containing potentially sensitive extracted content.
* OCR retries delete successes; native PDF reparsed for page count.

These are code-confirmed risks; no production traces or live provider benchmark were executed.

## Proposed modifications and migrations

Keep the normal path. Add configurable preflight/routing, source offset chunks with durable identity and child splits, bounded independent jobs on existing extraction queue, application merge into existing tables, persistent evidence provenance, and evidence-budgeted global synthesis. Reuse AnthropicClient transport/auth and AI runs, with typed failure policy, schema-constrained incremental calls, centralized task routing/pricing and request telemetry. Add optional visual checkpoint jobs with local filtering and durable asset references. Keep public Ready for optional partial completion; add additive processing metadata. Resume from checkpoints and route all reprocess entry points consistently.

Forward-only additive migration: document preflight/pipeline metadata; document chunks and evidence with workspace/document foreign keys and uniqueness; additional AI telemetry columns. No historical intelligence rewrite, production seeder dependency, status-enum replacement, or billing changes. New prompts need application fallback or migration.

## Compatibility and untouched areas

Preserve existing environment names and credential, existing Haiku default, successful normal requests, auth/policies/security gate, malware behavior, credit/accounting calls, Matter/QA/comparison contracts, Voyage storage and historical rows. Configured Sonnet must be explicitly enabled after account access verification; do not silently select an inaccessible model. API docs verify JSON schema (`output_config.format`), count_tokens and short-lived caching; account access remains unverified. Existing tests that require whole-summary rejection must change to the requested per-item policy. No deployment, push, Railway changes, remote DB commands or report contents in git.

## Tests and baseline

Existing suites cover upload/auth/workspaces/billing, text/DOCX extraction, OCR rasterizer, structured responses, summary prompt versions, KPI identity/concurrency, lifecycle/transaction recovery, QA, Matter/comparison, source validation and frontend processing/retry contracts. Baseline backend suite started before code changes; results tracked separately. New tests will use provider fakes and synthetic report fixtures.
