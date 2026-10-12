# Document Intelligence Revision V1

## Pre-change ownership inventory

This inventory was completed before schema or implementation edits.

| Artifact | Current owner / mutation | Class | Revision decision |
| --- | --- | --- | --- |
| `documents.extracted_text`, `ai_pipeline`, `insights`, status | extraction and synthesis jobs overwrite one document row | C | Candidate work copy; published reads use revision-owned text and pipeline metadata |
| `structured_extractions` | file/text/parser hash with one active document row | B | Pin exact structured extraction ID in revision; candidate `active` is not publication authority |
| `document_chunks`, `document_source_spans` | pipeline key / extraction version | B | Revision maps to exact pipeline and span version; chunks remain processing artifacts |
| `document_evidence` | pipeline key, canonical identity | B | Revision maps to exact pipeline; membership freezes at publish |
| KPIs, charts, chart points | document-only rows replaced by `GenerateInsightsJob` or visual job | C | Add nullable revision ownership to KPI/chart parents; chart points inherit chart ownership |
| risks, deadlines, entities | document-only rows deleted and recreated by independent jobs | C | Add nullable revision ownership; candidate writes cannot delete published rows |
| document type classification, intelligence summary | one mutable row per document | C | Allow one row per revision while retaining one legacy null-revision row |
| source provenance sets, anchors, relations, role bindings | source text hash + pipeline key; graph atomically published per set | B | Revision pins exact provenance set; anchor rows remain set-owned |
| Stage A / B1 | read-time projection of evidence and typed rows | D | Build from one published read document and revision-scoped typed rows |
| B2 `brief_synthesis` checkpoint | pipeline key plus input hash; optional after B1 | B | Input identity includes revision ID; read must pin revision |
| processing jobs | mutable attempt state per document | B | Add revision ownership so old attempts cannot complete a new candidate |
| `document_ai_runs` | provider attempt and accounting history linked to chunk/operation | A | Keep immutable spend history; do not rewrite costs |
| embeddings, visual charts, page flags | optional or source-related | B/C | Scope visible charts to revision; embeddings/page flags remain outside core publication |
| document comparisons and Matters | separate analysis processes; can read current document state | E | Audit only, no redesign in this phase |
| source-reference endpoint | current document text/pipeline and active provenance lookup | C | Resolve through published revision, keeping authorization and bounded response |
| workspace insights trends | document KPI rows, no revision filter | C | Filter to each document's published revision or legacy row |

## Exact inconsistency

`StructuredExtractionStore::publish()` updates `documents.extracted_text` before `EvidenceMerger` and `TextRoleProvenanceProducer` run. `GenerateInsightsJob` and the optional intelligence jobs delete or overwrite typed document rows independently. `GenerateDocumentSummaryJob` updates its one summary row before marking Ready. None of those writes share a published revision pointer, so status checks and pipeline hashes do not establish a coherent user read.

## Chosen architecture and publication invariant

The feature flag `DOCINTEL_REVISION_V1` defaults off. With it on, `DocumentRevisionPublisher::begin()` locks one document, increments its generation, creates a candidate UUID, and supersedes the previous unpublished candidate. Queue jobs carry the UUID. The document row is the candidate work area; published reads use an immutable row in `document_intelligence_revisions`. Typed rows have nullable revision ownership. Existing null-owned rows remain legacy data. Incremental evidence and chunks retain their pipeline key; the revision pins that exact key and provenance-set ID.

The short publication transaction locks document then revision, validates generation, workspace, deletion, file hash, route, text, stage completion, summary, and (for incremental processing) the completed merge and matching published provenance set. It snapshots text, its hash, page count, pipeline metadata, insight metadata, parser identity and provenance identity, records the previous published ID and `initial`/`reprocess` reason, then changes the single document published pointer. This revision row is the durable publication audit entry, including generation and publish time; no contents are logged. A second publish of the same revision is idempotent. Normal short-document processing declares `textual.compatibility.v1`; structural proof is never claimed in the absence of a graph. PDF textual provenance remains publishable. Core revision rows cannot be updated through Eloquent once published.

**Invariant:** a user-facing document intelligence read first obtains `published_intelligence_revision_id`, then resolves text, typed rows, pipeline evidence, structured extraction and provenance through that identity. A candidate may be persisted, but it is never selected by the published read path. The old pointer remains valid throughout reprocessing and after candidate failure. The pointer flip is one database transaction; readers can see either committed pointer, never half a flip.

The document processing status is separate from availability of a published result. Detail responses expose `intelligenceRevisionId`, `intelligenceRefreshInProgress`, and `intelligenceProcessingStatus`; list responses expose revision ID and `intelligenceAvailable`. A published view shows the old result as Ready while exposing any refresh failure through `intelligenceProcessingError`. First processing with no published pointer yields processing/failure state and no candidate intelligence.

## Generation ownership, concurrency, and retries

The database enforces unique `(document_id, generation)` and revision ownership. `begin`, candidate text writes, route selection, typed writes, provenance publication, failure callbacks and final publication use generation checks, with document row locks at mutation boundaries. Two starts serialize on the document row. The later accepted generation supersedes the older unpublished candidate; a late older publish throws. Duplicate stage executions use revision-scoped processing-job attempts and existing chunk/pipeline idempotency. OCR page checkpoints are keyed by revision and page; search embeddings are keyed by revision and chunk. A late scan, extraction, insights, OCR, merge or synthesis failure cannot change a newer document's status.

Lock order is document then revision/child rows. Provider calls remain outside publication transactions. A worker crash before publication leaves a non-visible candidate for retry or failure; a crash after the pointer commit leaves a valid published revision even if optional follow-up jobs do not run. Previous revisions and failed/superseded candidates are retained. No pruning worker was added. One current pointer exists per document.

Deletion, workspace ownership, generation and incompatible terminal state are checked under the publication lock. Source requests reauthorize with `DocumentPolicy::view` on every request; source IDs confer no lasting permission. Old source IDs are stale after a new pointer is published, without fuzzy remapping. Existing legacy documents stay unversioned and readable; the system does not invent a coherent historical baseline from unproven old rows. Their first V1 reprocess creates a new candidate; no mass AI reprocessing occurs.

## Revision-owned artifacts and read pinning

New nullable ownership is on KPI, chart, entity, risk, deadline, type, summary, processing-job, OCR-result and search-embedding rows. Composite foreign keys enforce document/workspace/revision agreement for typed rows, processing attempts, OCR checkpoints and embeddings. Chart points inherit ownership from their parent chart. Normal typed intelligence is created under its candidate revision and never deletes a published revision's rows. Incremental canonical `document_evidence`, `document_chunks` and source-provenance rows are addressed through the revision's unique pipeline key, exact text hash and pinned provenance-set ID. Structured extraction is pinned by ID and parser/policy metadata. Page count is also snapshotted so PDF source-page lookup cannot use a candidate's page count with old text.

`DocumentIntelligenceService::publishedView()` is the read boundary for document/detail, intelligence, Findings/Quality, source navigation, B1 and related typed-resource endpoints. Direct workspace trends and typed projection queries are filtered to published revision ownership. Stage A memoization includes revision ID. Dedicated paginated typed endpoints include `intelligenceRevisionId`; document and source DTOs include it as well. Search embeddings and QA context are revision-scoped. QA checks that vector hits still match the current pointer before loading typed metadata; a publication race yields no stale/mixed QA context. Comparison excerpts use a published view, with an honest empty result for a first unversioned candidate. There is no new source DTO cache.

B2 remains optional. Its input proof contains revision identity and provenance-anchor proof; B2 jobs check the published pointer before running, and the read service resolves only the pinned pipeline key and input hash. A late B2 result for revision N cannot appear on N+1. No GET endpoint dispatches a provider call. Publication does not wait for B2, visual planning or embeddings. Their rows are revision-addressed or are explicitly optional outputs; they do not alter the core evidence revision.

## Parser-only enrichment and lifecycle limits

A parser-only upgrade is not automatically activated against existing semantic evidence. The previous extraction report's deterministic parser/provenance APIs remain available, but V1 requires either a safe exact binding to an existing immutable semantic revision or a new candidate. This phase does not run AI or debit credits for parser-only work. Existing historical documents continue through the textual legacy path until explicitly reprocessed. The new V1 full reprocess of a normal document reruns scanning/extraction and analysis; incremental reanalysis starts a fresh pipeline key. Section-only retry remains rejected under the V1 flag because mutating a published core section in place would break immutability.

## Fallback and failure audit

Expected weak capability is explicit: a normal short document uses textual typed source references; an incremental PDF may publish a textual provenance set with no table relations. Missing or crashed incremental provenance does not satisfy publication. A failed reprocess leaves the old pointer and all old typed/evidence/provenance rows intact. A first failure leaves the pointer null and candidate output hidden. An older worker cannot attach old provenance to new text or publish its older generation. Stale source references return `STALE_SOURCE_REFERENCE`; candidate/failed references are unavailable. Structural contradictions continue to fail closed through `StructuralClaimGuard`. No hidden XLSX/formula cache data is promoted to new structural proof. No new chunking semantics were added; the previous bounded table chunker remains in place.

## Schema, storage, deployment and rollback

Additive migrations:

1. `2026_10_11_000005_create_document_intelligence_revisions.php`: revision table, document pointers/generation, typed ownership.
2. `2026_10_11_000006_enforce_intelligence_revision_ownership.php`: state/uniqueness checks and composite typed ownership foreign keys.
3. `2026_10_11_000007_scope_ocr_results_to_intelligence_revisions.php`: OCR and processing-attempt ownership.
4. `2026_10_11_000008_scope_search_embeddings_to_published_revisions.php`: revision-scoped search index rows.
5. `2026_10_11_000009_pin_page_count_to_intelligence_revisions.php`: immutable published page count.
6. `2026_10_11_000010_record_intelligence_publication_history.php`: durable previous-pointer/reason audit fields.

Each full reprocessing run may add a complete new set of typed rows, one revision row, new incremental evidence/chunks/provenance or normal textual rows, and optional embeddings. Current, historical published, failed and superseded rows remain distinct. Future pruning must account for audit/export/comparison references and any policy for historical source links. This phase does not prune.

**Production order:** take a database backup; deploy additive migrations with the flag off; deploy new code; drain and restart old workers and scheduler queues; verify no old in-flight job can write document-current columns; enable `DOCINTEL_REVISION_V1`; smoke-test a new upload, normal and incremental reprocess, old document read, source stale behavior, and B1/B2 without triggering GET provider calls. An old worker running after flag activation can still write legacy mutable columns without the new generation fence, so draining is mandatory. To roll code back, disable the flag and drain new workers first. Schema rollback after revision-owned data exists is intentionally refused by migrations that would otherwise collapse multiple type/summary or embedding rows; export/retain history before any such rollback. No production migration, deployment, push or merge was performed.

## Performance measurement

Local disposable PostgreSQL 18, one process, rollback-only synthetic fixtures, `DB::enableQueryLog()` and `hrtime()` around each call; measurements include local database and PHP work but no network/provider time. Normal V1 fixture with five KPIs: B1 6 queries / 33.12 ms, textual source resolution 5 queries / 9.16 ms, publication 8 queries / 21.41 ms. Five-hundred-KPI fixture: B1 6 queries / 9.88 ms, source 5 / 7.51 ms, publication 8 / 13.54 ms. The first run paid cold PHP/DB cache costs, so these are samples rather than latency percentiles. A separate incremental fixture with 1,001 anchors and 100 direct relationships: B1 10 queries / 34.50 ms; source resolution 8 queries / 23.67 ms, returning 24 bounded relationships. Peak PHP allocated memory was 40.5 MiB. A focused HTTP source test also enforces at most 25 queries including authorization. The benchmark is synthetic and does not establish production p95 latency or provider throughput; it shows no query-count growth with the measured graph size.

## Verification

Focused revision/incremental/source suite: 79 tests, 428 assertions; focused upload/queue suite: 21 tests, 356 assertions; broader pipeline/provenance/B1/B2/QA suite initially found one scan failure callback regression, which was fixed; failure rerun: 29 tests, 183 assertions; focused revision/failure/QA/source/B2 run: 76 tests, 532 assertions; final incremental/provenance focused run: 86 tests, 442 assertions; publication-audit focused run: 7 tests, 35 assertions. A mocked normal summary job test publishes a complete revision without a live provider request. **Final full backend suite: 1,382 tests, 1,381 passed, 9,670 assertions, zero failures/errors, one skipped** (121.7 seconds). Pint: passed. `git diff --check`: passed. The local full migration chain ran; migrations 000005–000008 rolled back/reapplied, and 000009–000010 each rolled back/reapplied. No paid provider calls were made.

## Remaining parser/product limits and frontend decision

PDF remains page/text only where layout structure is unavailable; PDF/OCR tables and geometry are not invented. DOCX/XLSX structure, projection mapping and bounded chunking remain as in Structured Extraction V2. Legacy documents are honestly unversioned, so atomic V1 coherence is guaranteed only once a V1 revision has been published. Historical source navigation is not exposed; stale references return a typed response. Section-only AI retry under V1 requires a new full candidate. Optional embedding/index readiness and visual chart completion are not part of deterministic B1 publication.

The frontend can use the opaque `intelligenceRevisionId` across detail, intelligence, typed lists and source responses; if separately fetched panels report different IDs, refresh. Backend reads already pin each response. Enable only after the worker-drain deployment sequence above.

| Required audit question | Answer |
| --- | --- |
| Can a user read mixed V1 revision intelligence? | **No** through revision-aware user reads. Historical unversioned documents retain their prior legacy contract. |
| Can an older V1 job publish after a newer run? | **No**; generation ownership and locked publication block it. |
| Can failed V1 reprocessing damage the previous published result? | **No**; its pointer and revision-owned rows remain intact. |
| Can B2 from an old revision appear on a new one? | **No**; pipeline/revision input identity is pinned. |
| Can source navigation resolve against a different revision than the verifier? | **No** for V1; it uses the pinned evidence/provenance set and exact text hash. |

**Frontend readiness:** V1 revision-aware document intelligence and source navigation are ready for frontend integration after the worker-drain deployment sequence. Legacy documents remain marked unversioned until reprocessed; the UI should use the returned revision ID to keep separately fetched panels aligned.
