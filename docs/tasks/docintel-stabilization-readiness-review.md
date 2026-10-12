# DocIntel stabilization: local review and Revision V1 activation plan

This is a review of the uncommitted `fix/docintel-stabilization-revision-v1` backend and frontend branches. It uses only local repository code and documentation. The separately titled **“DocIntel — Phase 1 system architecture audit”** was not found locally and remains an unverified external reference. The local `deterministic-hardening-phase1-audit.md` and Phase 3A task were inspected as historical context, then checked against current code.

## Verified issue inventory

| Area | Current finding | Correction or boundary |
| --- | --- | --- |
| Admin global role update | Target membership was not checked by the role mutation. | Lock actor scope and target; allow only a target whose sole membership is the actor's current workspace; retain one active administrator. Global roles remain a Personal-era model. |
| Search and Q&A | Raw vector SQL could select soft-deleted documents. | Both retrieval paths exclude deleted documents before top-K selection; published/legacy embedding filters remain. Embeddings are retained for history or restore but never exposed for deleted documents. |
| Upload | A failed database write after private storage could orphan an object; post-commit queue delivery had no durable admission marker. | Remove unowned objects after any creation failure, preserve duplicate response, and commit a pending dispatch marker with the document. A scheduled local command marks stale unclaimed uploads Failed and releases reservations without replaying billable work. Cleanup failures remain logged for operator reconciliation. |
| Revision reads | Matter aggregate typed rows lacked each document's published pointer. | One service scope pins each row to its document's published revision, with a strict legacy generation-zero path. Document reads reuse the existing published view. |
| Comparisons | Saved structured snapshots lacked explicit source revision identity. | New comparisons store immutable source revision IDs; old records are marked unknown rather than assigned invented provenance. |
| Exports | Several browser requests could cross publication boundaries or pagination. | Document and Matter export endpoints materialize authorized data in one repeatable-read database snapshot; client DOCX generation and audit events remain. Limits return 422 rather than silently truncating. |
| Frontend refresh | A Ready published view could mask background regeneration. | Bounded abortable polling follows the refresh signal; a pointer change remounts dependent intelligence, while the prior publication stays visible during processing. |
| Structural provenance | Native DOCX and XLSX paths already existed; PDF/OCR table and layout proof remains limited. | Preserve existing format-specific structure, persist bounded Tesseract word boxes when available, and report ambiguous/unavailable source matches explicitly. Do not infer PDF coordinates or scanned table cells. |

## Read contract and compatibility

`DocumentIntelligenceService::publishedView()` pins a single document response. `publishedRows()` pins a multi-document typed query to **each** document's pointer. A candidate never becomes a completed read until `DocumentRevisionPublisher` commits its pointer flip. A failed or superseded candidate leaves the previous publication available. Documents with generation zero and no pointer retain the legacy read path; later generations without a publication expose no candidate intelligence. Typed APIs, Matter counts, comparisons and report snapshots use these contracts. Historical comparison rows keep their original metadata and report `unknown_historical` provenance.

The search policy retains existing embeddings on soft delete for possible recovery and audit history; SQL access filters deleted documents, and revision filters prevent candidate embeddings from entering search or Q&A. No background embedding deletion or historical intelligence pruning is introduced.

## Capability limits and follow-up

- **Blocks reliable full Phase 3A provenance:** native PDF table/cell geometry, scanned-table structure, rotated/multicolumn OCR reading order, and automatic OCR/native duplicate resolution remain unverified. Tesseract word boxes use image pixels only when image dimensions and box bounds validate; they do not establish table relations. Native DOCX/XLSX paths and parser-specific metadata need real-world fixture coverage for every unsupported construct before claiming complete format support.
- **Historical limits:** legacy intelligence and old comparisons lack immutable source revision IDs. Their history remains readable, but exact provenance cannot be reconstructed. A failed private-storage cleanup can leave an orphan and needs operator reconciliation.
- **Frontend integration:** cross-tab refresh uses independent bounded polling; no shared tab coordinator exists. Existing client DOCX generation still depends on a successful complete snapshot response; limits are explicit failures.
- **Future product work:** workspace-specific roles require the future Organization design. Notes and user annotations may reuse immutable source anchor IDs only with revision, document and workspace ownership; do not treat a text hash as a unique occurrence ID.

## Ordered production activation procedure — not executed

1. Keep `DOCINTEL_REVISION_V1=false`. Back up the database and confirm a tested restore route. Record current app, worker and scheduler versions and queue depths.
2. Apply the **additive** source/structured migrations already required by the deployed code, then revision migrations `2026_10_11_000005` through `000010` in filename order. Check `migrate:status` and constraints. Do not run destructive rollback migrations on production history.
3. Deploy compatible API code with the flag off. Deploy the frontend against the new snapshot endpoints. Verify authenticated legacy reads, admin scope, deleted-document search, upload, report audits and no paid GET calls.
4. Drain old Horizon workers and in-flight jobs before flag activation. Pause new processing admissions while draining if needed. Old workers can still write mutable legacy columns. Restart Horizon and scheduler on the new code; confirm the upload-dispatch recovery command is scheduled and no stale pending markers accumulate. Decide explicitly whether documents already Processing finish under the old code before the cutover or are retried as new candidates after drain; never let old and new workers process one generation together.
5. Refresh application/config caches and worker processes after setting the flag in a controlled maintenance window. Verify the effective config value on API, Horizon and scheduler independently. Do **not** enable the flag from this review or from test success alone.
6. Smoke-test a new upload, one normal reprocess, one incremental reprocess, an old legacy document, a failed candidate with prior publication, source stale/ambiguous behavior, Matter aggregation, candidate versus published search/Q&A, comparison status, and document/Matter DOCX exports. Use approved provider/credit test accounts; avoid accidental billable retries.
7. Watch publication failures, stale-generation rejects, queue depth/age, pending upload markers, credit reservation releases, search pointer mismatch, source-resolution failures, snapshot 422/5xx, frontend poll errors and report audit events. Compare selected document counts and current pointers before and after activation.
8. If activation fails, stop new processing admissions, disable the flag, refresh config, drain new workers, and restart the prior compatible worker/API release. Preserve additive schema and all revision rows; do not roll back ownership migrations once revision-owned rows exist. Repair affected documents only through explicit, idempotent operator workflows after inspecting billing and queue state.

This review is **not** production activation evidence. There is no staging environment identified by the local repository instructions, so rollout must include a maintenance window and backup/restore verification.

## Local verification

The final `php artisan test` run used an isolated local PostgreSQL 18 database with `DB_HOST=/tmp`, `DB_PORT=55432`, `DB_DATABASE=docintel_stabilization_verify`, `QUEUE_CONNECTION=sync`, and mocked AI providers: **1,408 tests, 1,407 passed, 9,785 assertions, one skipped, zero failures**. The skipped ClamAV CLI test requires `clamscan`, which is absent locally. `php artisan migrate:status` showed the complete chain through `2026_10_11_000010` applied in that disposable database. Focused search, Q&A, upload fault injection, report snapshot, Matter revision, comparison and source-reference tests passed. Frontend `npm run test` passed 130 tests in 24 files; `npm run typecheck` and `npm run build` passed. `vendor/bin/pint --test --dirty` and both repositories' `git diff --check` passed. Whole-repository `vendor/bin/pint --test` reported formatting failures in untouched historical files, including backup migrations; this review did not reformat them.
