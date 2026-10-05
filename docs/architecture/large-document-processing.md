# Incremental document intelligence

Implementation started 2026-10-04; final local verification 2026-10-05. Read [the pre-change audit](large-document-audit.md) first. No push, deployment, production setting change, live generation benchmark, or production data migration was performed during implementation.

## Resulting flow

1. Existing upload authorization, workspace hash uniqueness, entitlement reservation, audit and storage.
2. Existing default-queue ClamAV scan. Security failures remain terminal.
3. Extraction queue: native PDF (single parse, form-feed page boundaries), safe DOCX extraction, existing XLSX extraction, or configured OCR. OCR page results survive retries; raster assets use the shared documents disk, with ID/path-only job payloads and cleanup.
4. Preflight after text persistence: tiny inputs use a byte upper bound without HTTP; larger inputs use Anthropic's free token-count endpoint. Cache by text hash/model. If counting is unavailable, label the byte/3 estimate explicitly. Input beyond the old 60,000-character limit also routes incrementally to avoid silent truncation.
5. Normal documents retain the existing type/entity/risk/deadline batch, insights/KPIs, grounded summary and embeddings. No extraction chunks, per-chunk summaries or merge calls. Normal summary now budgets whole evidence records and tolerates invalid optional siblings. Existing independent insights/batch scheduling remains; normal summary can still precede insights/KPIs.
6. Large documents: plan source-text offsets → at most two independent extraction jobs outstanding per document → checkpoint each result → deterministic merge into existing intelligence tables and persistent document evidence → optional targeted reference resolution → existing credit accounting and Ready → one evidence-based global synthesis, independent optional visuals, existing embeddings.
7. Optional failures keep Ready with additive partial/coverage metadata. Failed required extraction leaves Needs Review with completed chunks retained. Failed prerequisites still use existing Failed behavior. No new document status enum.

Chunks are processing units. The persistent document evidence store is the knowledge boundary; there are no rolling summaries.

## Model routing and authentication

All requests reuse `ANTHROPIC_API_KEY`. No additional credential exists.

| Task | Effective model | Reason / fallback |
|---|---|---|
| Incremental extraction; normal type/entities/risks/deadlines/insights | `ANTHROPIC_EXTRACTION_MODEL` | Defaults to existing `ANTHROPIC_MODEL`, then existing Haiku 4.5 pinned ID. Extraction model was not upgraded. |
| Document summary, assessment, takeaways/findings, trends, tensions, questions | `ANTHROPIC_SYNTHESIS_MODEL` | Recommend `claude-sonnet-4-6` after account access check. If unset, legacy `ANTHROPIC_MODEL` remains effective. |
| Targeted context resolution | Same synthesis model | At most one small batched request for selected unresolved references. |
| OCR, chart vision, document Q&A, comparison, KPI identity adjudication | Existing `ANTHROPIC_MODEL` | Preserve existing routing and contracts. |

No automatic model substitution follows a provider access failure. `php artisan docintel:verify-models` displays effective routing without credentials. `--check-access` queries the Models API without generation; failure may mean access or connectivity trouble. It has not been run against the production account.

Stable provider schemas use `output_config.format` on supported models; source IDs are validated in PHP, not generated as schema enums. The incremental extraction and context schemas have application versions; synthesis reuses the active database prompt, plus its stable schema. Sonnet 4.6 gets configured `output_config.effort` (medium default). Existing normal extraction prompt/parser infrastructure remains. The cached extraction system block uses the default short-lived ephemeral mechanism; cache hits depend on provider minimum sizes and are never assumed.

Official API references checked during implementation: [models/Sonnet 4.6](https://platform.claude.com/docs/en/models/sonnet-4-6/overview), [structured outputs](https://platform.claude.com/docs/en/build-with-claude/structured-outputs), [token counting](https://platform.claude.com/docs/en/build-with-claude/token-counting), [prompt caching](https://platform.claude.com/docs/en/build-with-claude/prompt-caching). Account access and real-document accuracy remain rollout checks.

## Configuration and chunking

Related defaults live in `config/document_intelligence.php`, not scattered ENV variables:

- Large threshold: more than 14,000 extracted tokens, or beyond the legacy character limit.
- Target: 14,000; accepted maximum before generation: 18,000; overlap: approximately 400 tokens, capped at 15% of the window.
- Initial density calibrated from document count; every extraction unit gets a free token check before generation. On count failure, a conservative byte upper bound prevents an oversized paid request.
- Boundary preference: heading, blank-line block, form-feed page, sentence, UTF-8-safe hard boundary. Overlarge/unstructured tables can still require hard splits. No assumption that headings exist.
- Offsets count Unicode characters. Rows store offsets, hash, page range when proven, estimated/verified token count, overlap, versions, ancestry, status, attempts and timestamps. They do not store duplicate source text.
- Only the offending unit splits after truncation, context overflow or request timeout. Children partition its source range without extra overlap; other checkpoints remain intact. Maximum depth six; minimum source length 1,000 characters. Exhaustion becomes Needs Review.
- No page claim is made when old extracted text cannot prove a page map.

## Concurrency, retries and recovery

Existing Horizon extraction queue stays at two processes. Application scheduling permits at most two outstanding extraction jobs per document and two optional visual jobs after extraction. A coordinator dispatches and exits; no worker waits on children. Deployment replicas multiply the global worker limit, so review total replica count before raising it. Existing local 40 requests/minute counter remains; provider 429 handling is authoritative.

| Failure | Incremental behavior |
|---|---|
| 429, provider 5xx, temporary network failure | One provider request per job execution; up to three explicitly scheduled attempts, exponential backoff plus jitter for extraction, bounded Retry-After. Summary retries are bounded and delayed. |
| Request timeout | Split the extraction unit; reduce summary evidence (at most two reductions). A single-image visual timeout becomes partial. |
| Hard worker interruption after possible provider send | Durable `uncertain`; never automatically replay an ambiguous paid request. Explicit core resume splits failed/uncertain input. Exactly-once external billing cannot be guaranteed after an unknowable network outcome. |
| `max_tokens` / context overflow | Split only offending extraction input. Summary retries with half the evidence budget, at most twice; unchanged evidence hash cannot issue a duplicate request. |
| Invalid extraction schema/quote/date, deterministic 4xx | Persist failure; no identical queue retry. |
| Invalid optional summary item | Drop that item, retain valid siblings, count drops in AI telemetry. |
| Missing/invalid required summary fields | One targeted repair for executive summary/key findings; optional siblings retained. Unrepairable output is a failed optional summary stage. |
| Budget exhausted or unknown model price | Preserve completed work. Stop the affected request; core extraction becomes Needs Review, optional work becomes partial. |
| Visual failure | Retain other successful visuals and Ready status; no repeated oversized request. Smallest batch is one image, so there is no smaller batch to retry. |

Chunk jobs use `tries=1`, timeout 150s (HTTP 100s); merge timeout 120s/tries 2, summary timeout 140s, visual planner 120s/tries 1, visual request job 90s/tries 1 (HTTP 55s). Existing 360s Horizon timeout and Redis `retry_after >= 390` remain unchanged. Normal jobs keep their existing corrected-output retry/transport conventions and completed-stage checks; new queue overlap middleware prevents simultaneous duplicate deliveries. Their crash window between a provider response and result persistence is not converted into incremental checkpoints.

`docintel:resume` runs every five minutes through the existing scheduler, recovers lost dispatch, marks stale in-flight calls uncertain, restores saved summary results, and resumes optional scheduling. Settled documents leave scheduled recovery. Completed source chunks are never re-extracted merely because summary or visuals failed. Checkpoints are scoped to workspace + document + text/model/pipeline/prompt identity; there is no cross-workspace cache. Existing upload hash deduplication remains workspace-scoped.

## Merge, context and source integrity

- Entity matching uses case/whitespace/punctuation normalization within type, and unambiguous explicitly evidenced parenthetical aliases. No fuzzy/name-similarity merging, broad legal-suffix stripping or guessed abbreviations.
- KPI matching reuses canonical definitions and aliases with AI adjudication disabled during bulk merge. Observation identity preserves definition, subject, period, unit, value, actual/target and measurement basis. Decimal punctuation is preserved. Different years/values remain distinct rows.
- Deadlines/obligations preserve label, responsible subject, date and meaning; sharing a date is insufficient to merge. Obligations continue to use existing deadline rows.
- Facts merge conservatively, including their exact quote. Ambiguous entity/KPI identities remain separate instead of forcing a model merge.
- Unresolved references are persisted. Retrieval ranks nearby and global evidence with lexical overlap, takes up to three candidates for up to eight references, then performs one bounded optional resolution request. Only supplied candidate IDs with confidence >= .98 can be recorded as inferred targets. Existing document embeddings are generated later, so this phase does not pretend to have semantic retrieval available. Additional/unresolved references remain explicit and excluded from synthesis until resolved.
- Each extracted quote must occur verbatim in its slice. Merge stores original offsets, chunk identity, quote and page when known; all duplicate source locations survive. Existing `entity:`, `risk:`, `deadline:` and `kpi:` IDs remain. Generic evidence uses `fact:UUID`.
- The synthesis allowlist comes only from selected evidence of the current document/pipeline. Invalid source items are dropped independently. API adds evidence/source maps; frontend source links support facts and show coverage/partial notices.
- Historical intelligence is retained. New pipeline versions can create new evidence/KPI observations; old reviewed rows are not destructively rewritten. UI integrations still reading legacy rows may show retained historical records after a version change; review before broad version invalidation.

## Cost and Railway resources

One cheap structured extraction call per leaf, not one call per feature per leaf. Sonnet sees evidence, not the raw PDF again. Evidence selection orders deadlines/obligations and severe risks, metrics, other risks, definitions/entities, supporting facts. Budget is a conservative **byte upper bound** on evidence tokens (16,000 default), trimming complete objects and source links. This is deliberately stricter than filling 16,000 model tokens; metadata reports omissions. Prompt overhead is reserved separately in cost checks.

Provider pricing lives in one config table (USD/million input, output, 5-minute cache creation and cache read tokens). Budget grows from $0.50 + $0.025 per 1,000 extracted tokens, capped at $10 by default. Durable conservative reservations include failed/ambiguous requests and targeted repair headroom; they may stop before actual provider spend reaches the cap. AI runs report actual returned token usage and estimated cost separately. This budget covers new incremental/optional visual work, not preflight-free requests or legacy OCR/normal-stage generation. Customer credits, subscriptions and allowances are unchanged.

Visual selection requires meaningful PDF caption/context hints, filters by size/dimensions, near-uniform sampling and hash duplicates, caps at 12, and processes one image per independent job. Conservative filtering can miss unlabeled charts. Successes persist individually. No provider asynchronous batch API is used.

Native extraction parses once; generated chunk jobs reuse text. Temporary source downloads stream to private temporary files. OCR and visual assets live on the existing documents disk and are removed after use; ordinary finally/error paths clean local rasters. Hard process/container kills can leave local temp files until host cleanup. Queue payloads contain identifiers/asset paths, not text or image data. Workers still load a document's extracted text to slice it; SQL range reads and streaming Smalot/XLSX replacements are not introduced. Existing PhpSpreadsheet whole-sheet materialization and upfront scanned-PDF rasterization remain resource limits to benchmark separately.

## Database and files

Migration: `2026_10_04_000003_add_incremental_document_processing.php` (additive, no seeders required).

- `documents.ai_pipeline`: nullable JSONB preflight/version/coverage/budget/recovery metadata.
- `document_chunks`: UUID PK; document/workspace FKs; nullable parent FK installed after table creation; unique `(document_id,pipeline_key,identity)`; lookup index `(document_id,pipeline_key,status)`; stage/hash/version/offset/page/token/overlap/attempt/status/cost/result/timestamps.
- `document_evidence`: UUID PK; document/workspace FKs; unique `(document_id,pipeline_key,identity)`; index `(workspace_id,document_id,pipeline_key)`; kind, public source ID, structured data and provenance JSONB.
- `document_ai_runs`: indexed nullable chunk UUID (intentionally no cascading FK, preserve audit records), pipeline version, request attempt, cache tokens, duration, worker process peak memory, estimated cost, failure class, provider request ID, partial/trim flags, optional drop count.
- No historical backfill, status enum change, destructive data rewrite or billing migration.

Important file groups:

- Configuration: `config/document_intelligence.php`, `config/services.php`, `.env.example`.
- Services: `AnthropicClient`; `AI/AiModels`, `AI/AiPricing`, `AI/Incremental/*`; extraction/storage/OCR/PDF visual detector; reprocessor and intelligence aggregation.
- Jobs: source chunk, deterministic merge, per-image visuals; existing text/OCR/summary/insights/embeddings/visual-planning and intelligence dispatch/skip concerns.
- Models/migration: `DocumentChunk`, `DocumentEvidence`, `Document`, `DocumentAiRun`; migration above.
- Validation: `ResponseValidator`, stable evidence/synthesis schemas and verbatim/source/date checks.
- Frontend: `CA/src/types.ts`, `CA/src/components/DocumentIntelligencePanel.tsx`.
- Tests: incremental feature suite, structured validation/output, extraction, lifecycle failure assertion, synthetic five-page PDF; frontend incremental notices.
- Tooling/docs: benchmark, resume, model verification commands, scheduler, this guide and pre-change audit.

Existing uncommitted lifecycle/stage changes and the frontend failure test were preserved; they are not all attributable to this refactor.

## ENVIRONMENT CHANGES REQUIRED

No variable was renamed or removed. No existing secret value should be copied into reports. Variables below are backend configuration; the React build needs none. Set model/routing/budget overrides consistently on **backend/web and Horizon workers** (and scheduler if separately deployed). After changes rebuild Laravel config cache and restart those PHP processes.

| Variable | Status | Old / new name | Recommended production value | Required? | Secret? | Services | Restart/redeploy | Purpose |
|---|---|---|---|---|---|---|---|---|
| `ANTHROPIC_API_KEY` | UNCHANGED | same / same | Keep existing secret | Existing required | Yes | Backend/web + Horizon | No change needed; restart if rotated | All Anthropic authentication |
| `ANTHROPIC_MODEL` | UNCHANGED | same / same | Keep current working model | Existing optional default | No | Both | Only if changed | Legacy tasks and backward-compatible fallback |
| `ANTHROPIC_EXTRACTION_MODEL` | NEW | none / same | `claude-haiku-4-5-20251001` if this is the current working model | Optional; falls back to `ANTHROPIC_MODEL` | No | Both | Yes when set/changed | High-volume extraction |
| `ANTHROPIC_SYNTHESIS_MODEL` | NEW | none / same | `claude-sonnet-4-6` **after access verification** | Required to opt into Sonnet; otherwise optional | No | Both | Yes | Global synthesis/context; unset retains legacy model |
| `ANTHROPIC_SYNTHESIS_EFFORT` | NEW | none / same | `medium` | Optional | No | Both | Yes if changed | Effort only on explicitly supported models |
| `DOCINTEL_INCREMENTAL_PROCESSING` | NEW | none / same | `true` after staging acceptance; `false` during coordinated rollout | Optional; default true | No | Both | Yes | New-document preflight/large routing switch |
| `DOCINTEL_MAX_DOCUMENT_COST_USD` | NEW | none / same | `10`, then tune using measured reports | Optional | No | Both | Yes if changed | Maximum provider-spend reservation per incremental document |
| `ANTHROPIC_STRUCTURED_MAX_TOKENS_CEILING` | UNCHANGED (newly documented in example) | same / same | `8192` | Optional, existing support retained | No | Both | Only if changed | Existing normal-path corrected-output ceiling |

All other Anthropic, OCR, security, queue, storage and billing ENV names retain their meaning. There are no Sonnet/Haiku-specific API keys. Chunk tuning, concurrency, thresholds, retry count, evidence budget, visual cap and prices live in application config to avoid ENV sprawl.

## Benchmark procedure

### Automated, no paid provider requests

From `CA_BACKEND` with a confirmed local test DB/Redis:

```sh
php artisan test --compact --filter=IncrementalDocumentPipelineTest
php artisan test --compact
php artisan docintel:benchmark --text=/absolute/path/to/small-synthetic.txt
php artisan docintel:benchmark --text=/absolute/path/to/giant-synthetic.txt
```

Text benchmark only plans. It does not parse PDF, write rows, dispatch jobs or call Anthropic. It reports estimated tokens, route, chunk count, planned generation count, duration and process peak memory. Planned normal requests = six existing text-generation stages; optional calls/retries are excluded.

Observed local planning examples: 14 estimated tokens → normal, zero extraction chunks, 0.112 ms, 38,273,024-byte process peak. 448,334 estimated tokens → 33 chunks, 34 planned core generation requests, 325.541 ms, 42,467,328-byte process peak. These are single local observations, not end-to-end or comparative provider results.

### Controlled old-route versus incremental-route staging run

1. Verify staging DB/Redis/storage are isolated from production and use a dedicated authorized benchmark workspace. Never run a local Horizon connected to remote production. Obtain the report locally outside git; no World Bank report is committed here.
2. Use the same extraction model, synthesis model, OCR/visual options, source files and worker count in both runs. Record the code revision and active prompt versions. To benchmark the intended model change separately, repeat with explicit Sonnet synthesis after checking access:
   `php artisan docintel:verify-models --check-access`.
3. **Legacy route:** set `DOCINTEL_INCREMENTAL_PROCESSING=false` in the isolated staging service configuration, rebuild config (`php artisan config:cache`) and restart its workers. Upload several small PDF/DOCX/XLSX fixtures and the large PDF through the existing UI/API; note document IDs and wall start/end time. Use separate workspace/upload records for the next route because existing workspace hash deduplication intentionally prevents duplicate uploads.
4. After each upload settles, run `php artisan docintel:benchmark DOCUMENT_UUID > /safe/local/path/legacy-DOCUMENT_UUID.json` in that isolated environment.
5. **Incremental route:** set the switch true, rebuild config and restart isolated workers. Upload identical fixtures in the separate benchmark workspace with equivalent permissions/settings. Export `php artisan docintel:benchmark DOCUMENT_UUID > /safe/local/path/incremental-DOCUMENT_UUID.json`.
6. Compare wall time; generation request count; input/output/cache tokens; estimated cost and unknown-cost calls; retries/failures; chunk/job attempts; entity/KPI/deadline/obligation counts; source validity; optional rejection; visual completion; and process memory. Review summary completeness manually against important sections, time periods, risks and contradictions. More entities alone is not an accuracy win. Check small-document latency across multiple runs, not a single timing.
7. In staging, interrupt an extraction worker after several completed units, restart, and run `php artisan docintel:resume DOCUMENT_UUID`. Completed units must not call the provider again; uncertain in-flight calls require review/explicit resume. Test a failed visual without losing Ready/core results.

The switch compares orchestration paths under the new telemetry/validator code; it is not an exact historical binary benchmark. For a strict old-version baseline use a separate checkout/database of the pre-refactor revision and compare provider-side usage too. Historical old AI rows may lack failed-request/cache/cost details. Benchmark `elapsed_ms` is the interval between first/last AI audit rows, not total upload-to-ready wall time; request count excludes free count/model endpoints. Checkpoint job attempts, legacy processing-stage attempts, provider retries and upload-to-last-completed-stage timing are reported separately; these are not Horizon infrastructure sampling. Peak memory is PHP worker process peak, not per-document RSS, container peak, CPU usage or Poppler RSS. Supplement with Horizon/Railway staging observations. No live accuracy/cost claims are made by this implementation.

## Human deployment checklist (not executed)

1. Review the diff and this guide. Back up the DB using established procedures. Verify staging model access and benchmark representative small, giant and scanned documents. Confirm shared documents storage is accessible to all workers.
2. Set rollout switch false on web/workers initially; configure extraction override only if needed. Verify Sonnet access before setting its model. Keep existing API credential. Review price table and budgets.
3. Coordinate worker drain/pause using existing operations procedures so old and new pipeline workers do not overlap while release code/config changes. Migration must precede code that queries new columns. Run the release migration: `php artisan migrate --force`. Do not run production seeders.
4. Deploy backend and frontend artifacts through the normal release process. On each backend/worker service run `php artisan config:cache`; rebuild route/view caches only as required by the existing release. Do not run `optimize:clear` indiscriminately against shared cache locks during active processing.
5. Restart/redeploy Horizon service to load the new classes/config (`php artisan horizon:terminate` under the existing supervisor when appropriate). Keep extraction maxProcesses=2, timeout360 and Redis retry_after>=390; no Railway resource settings change is required. Confirm the existing scheduler invokes schedule:run so five-minute resume runs.
6. After isolated smoke acceptance, enable incremental routing consistently and recache/restart. Smoke-test one small upload, one large upload, an explicit resume, source links, partial visuals and allowance accounting. Inspect safe telemetry for costs, unknown prices and deterministic failures; never log document payloads or credentials.
7. Rollback by disabling new routing and retaining the additive tables/columns and new worker classes until queued incremental work is drained/reviewed. The flag only gates new preflight routing; it does not discard in-flight checkpoints. **Do not run migration down in production** to roll back code: it deletes new evidence/checkpoints. Do not deploy old workers that cannot deserialize queued new jobs. Preserve data and explicitly coordinate any eventual rollback.

## Verification and limitations

Automated results and exact commands are recorded in the delivery report. The ClamAV executable integration test is skipped when the local executable is absent; malware/security mocks still run. No production migration or Anthropic generation was exercised. Model access, true report extraction accuracy, cache hit economics, multi-replica behavior and provider/Railway cost improvements require the staging procedure above. Existing normal-path summary/KPI timing and normal-path post-provider crash windows remain compatibility limitations. Upfront large scanned-PDF rasterization and PhpSpreadsheet memory use require separate real-file profiling. Conservative filtering/budgets intentionally permit partial results rather than unbounded spend.


### Final local verification — 2026-10-05

- Pre-change full backend baseline: 531 tests, 525 passed, six skipped, 2,934 assertions.
- Targeted verification during implementation: 117 passed, 473 assertions. Later cases also run in the full suite.
- Final `php artisan test --compact --log-junit /tmp/docintel-backend-final.xml`: **577 tests; 576 passed; one skipped; 3,154 assertions; no failures/errors**, 62.456 seconds. Skip: `ScanUploadedFileJobCliTest::test_real_clamscan_catches_a_real_eicar_string`, because local `clamscan` is not installed. Synthetic PDF fixture enabled the five rasterizer tests that previously skipped.
- `npm test -- --maxWorkers=1` in `CA`: **90/90 tests**, 19 test files, 41.03 seconds. An earlier concurrent frontend run encountered local five-second product-tour timeouts; serial verification passed.
- `npm run typecheck`: passed.
- `npm run build`: passed, 12.89 seconds.
- `npm run lint`: zero errors, five existing warnings in Chart, DashboardCanvas, DocumentTour and FilterContext.
- Pint `--test` across PHP files changed by this refactor: passed. A broader AI-directory style check also found pre-existing formatting findings in untouched DocumentIntelligenceEngine, PromptManager and DocumentContextRetriever; these files were not reformatted.
- `git diff HEAD --check` in both repositories: passed. PDF fixture is explicitly binary in `.gitattributes` so its required xref spaces/offsets are preserved.
- Credential-pattern scan of changed content found zero matches. Local secret files were not inspected or printed for that scan.
- Migration ordering, workspace checks, small routing, first dispatch, duplicate/redelivery, resume, source validation, optional partial completion, model routing, ENV example and Horizon/Redis timeout relationship were inspected. Tests used local Postgres/Redis and provider fakes; no live generation requests, production migration, push or deployment occurred.
