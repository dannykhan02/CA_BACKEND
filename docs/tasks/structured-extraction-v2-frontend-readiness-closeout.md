# Structured Extraction V2: frontend readiness closeout

## Decision

**STRUCTURED_EXTRACTION_V2_CLOSEOUT_PARTIAL.** The authorized bounded source endpoint and native-table chunk packing are implemented. The existing document and typed intelligence tables still do not support atomic old-or-new publication of a complete processing revision. The frontend must not treat source navigation as a complete revision contract yet.

## 1. Revision publication design and coherence invariant

Current production flow writes `documents.extracted_text` and activates `structured_extractions` in `StructuredExtractionStore::publish()`. The incremental pipeline subsequently writes chunks, canonical evidence, provenance, and the typed summary. `GenerateDocumentSummaryJob` writes typed rows before it sets `status=Ready`. These stages cannot be wrapped in one transaction because provider work is asynchronous and expensive. The durable pipeline key separates most incremental canonical evidence, but `documents.extracted_text`, KPIs, risks, entities, deadlines, and summaries are single-current-value records. There is no published-revision pointer binding them all.

The required invariant remains: each read must pin one published revision containing file hash, parser/policy identity, text hash, pipeline key, canonical evidence, typed intelligence, and provenance set. Candidate writes must remain invisible until a compare-and-swap publication on the intended processing generation. This was **not implemented**. A status check on the new source endpoint returns `SOURCE_NOT_READY` during `Processing`; it prevents that endpoint from showing a candidate, but it does not preserve an older source response during reprocessing and does not fix all existing intelligence endpoints.

`SourceProvenance::publish()` now locks the document and rejects a publisher whose current text hash or pipeline key differs, or whose document is deleted, Failed, or Needs Review. This also prevents an idempotent retry from reactivating an old set. Existing graph publication remains one transaction and identical retries retain one set. This is a publication fence, not the missing document-wide revision lifecycle.

## 2. Failure, retry, concurrency, and parser-only enrichment

An older extraction job can still activate its text after a newer extraction starts because no durable processing-generation owner is carried through extraction. Typed intelligence rows can be overwritten before final Ready; failed reprocessing cannot reliably restore the prior complete result. `SourceProvenance` fences late graph publication, and the source endpoint denies processing/deleted documents, but those measures do not satisfy concurrent revision activation. No parser-only reprocessing endpoint or lifecycle was added. Deterministic parser enrichment without AI remains conceptually possible only when old canonical quotes map uniquely to the new projection; ambiguous quotes require full reanalysis. No provider calls or credit changes were made.

B2 input identity now includes a deterministic hash of actual value-role anchor proof. A new graph with different anchor identities cannot reuse an incompatible old B2 checkpoint for the same pipeline. Empty role maps retain the prior hash behavior. Existing B2 read logic still requires an exact checkpoint input hash.

## 3. DOCX and XLSX chunking

`StructuredChunkPlanner` uses the parser's cell projections and native DOCX table paths or XLSX table range metadata. In span-grounded incremental mode, it groups a small native table when the complete group fits the existing partition token ceiling. Large tables split at existing source-span boundaries; chunks are exact `extracted_text` ranges, have stable hashes/order, use zero overlap, and do not insert synthetic header text. The chunk policy, parser version, and structured-extraction policy enter the incremental pipeline key for these formats; legacy keys remain unchanged.

This closes ordinary small-table splitting in the incremental span path. It does **not** add a new row-aware algorithm for the legacy chunk route, repeat multi-level headers/captions for large-table segments, or subdivide one source span that exceeds the provider ceiling. That last case fails with a typed planner exception instead of issuing an oversized request. Native XLSX tables are recognized; an unregistered worksheet region continues to use source spans without assumed header semantics. A merged header remains one source occurrence because no copied context is emitted.

## 4. Chunk source mapping and deduplication

Every new chunk stores raw start/end offsets and the SHA-256 hash of that exact raw slice. Labeled evidence spans retain their own source IDs. The new planner does not repeat source spans across chunks, so it cannot duplicate a canonical fact through chunk overlap or manufacture an offset for a copied header. Existing `EvidenceMerger` identity/upsert remains unchanged. The planner changes packaging only, not provenance or B1 ranking.

## 5. Source-reference DTO and endpoint

`GET /api/documents/{document}/sources/{sourceId}` is authenticated and throttled at 60 requests/minute. `DocumentPolicy::view` runs before resolution on every request. A valid source ID is scoped again to workspace, document, and current pipeline key. A source ID from another pipeline returns typed `409 STALE_SOURCE_REFERENCE`; unknown IDs return 404. Processing documents return `409 SOURCE_NOT_READY`; deleted documents fail route model binding. There is no source DTO cache and no bearer-style authorization embedded in IDs.

The JSON data includes controlled `state`, `sourceFormat`, `capability`, current text revision hash, bounded canonical quote preview and range if verified, direct role anchors, direct relationships, and exact parser location when one cell matches a canonical citation range. Anchors carry page/sheet/path, node type, offsets, bounded text preview, and small navigation metadata. The service loads at most 12 role anchors and 24 direct relationships, and returns at most 512 characters for each text field. It never recursively walks the graph. The DTO contains untrusted document text as JSON data; clients must render it as escaped text, never trusted HTML or Markdown.

`navigation_metadata` on source anchors records XLSX coordinate, merged ranges, visibility, formula presence, cache presence, and whether a value was literal or came from workbook cached formula state. Formula and hidden cells remain textual when Phase 3A declines semantic value binding. An exact single-cell projection can still expose their location and metadata without claiming structural field-role proof. PDF remains page/text only. Historical typed risk/deadline/entity/KPI references return textual preview where their owned rows exist; they do not invent raw offsets, geometry, or cells.

The endpoint uses the same `evidence_role_bindings` and anchor IDs that the verifier reads. It checks anchor hashes against the document text before returning roles or relationships. An unresolved or ambiguous exact cell projection remains textual. No fuzzy source search or graph expansion parameter exists.

## 6. Provenance capability and hidden XLSX policy

The DTO returns `TEXTUAL` or `STRUCTURAL_PARTIAL` and role-by-role anchor lists. It does not claim full structural proof from one value cell. Hidden XLSX content stays in compatibility text as before; hidden/very-hidden sheet state and row/column visibility remain metadata. Hidden or formula-cache values are not promoted to structural value bindings. A visible formula that references hidden content is not evaluated or traced.

## 7. Fallback audit

Safe: absent graph yields textual citation; PDF has page/text without invented tables; an exact cell projection can supply location without semantic role proof; a large source text yields a bounded preview. Unsafe paths remain blocked in the new endpoint: old provenance cannot be attached by text/pipeline mismatch, guessed source IDs are not fuzzy remapped, and processing candidates are not served. The broader existing read paths still lack a published-revision pin and are therefore a closeout blocker.

## 8. Performance and query bounds

The source endpoint has fixed graph query limits (12 role anchors, 24 direct edges) and one exact-cell projection lookup; no full structured JSON or document-wide anchor collection is loaded. A focused HTTP test enforces at most 25 database queries including auth and route binding on a representative XLSX source. The new planner caps 1,000 tables and 100,000 projected cells in its own walk and respects the existing partition token ceiling. Tests cover deterministic large-table splitting and bounded token counts. A representative many-anchor latency/peak-memory benchmark and B1 endpoint query measurement were **not completed**; performance readiness cannot be claimed on that basis.

## 9. Storage and migration

New additive migration: `database/migrations/2026_10_11_000004_add_source_anchor_navigation_metadata.php`. It adds nullable `source_anchors.navigation_metadata` and an owned `document_evidence` source lookup index. The local disposable PostgreSQL migration chain applied successfully; rollback of this migration and reapply both passed. No production migration was run. Deploy the migration before new workers/API code; old workers can leave metadata null. Rollback after new code is running would break source reads/writes, so roll workers back first. No mass historical reprocessing is required. Historical anchors simply lack this metadata.

## 10. Tests and quality gates

Focused source/chunk tests: 6 passed, 37 assertions. Broader focused run: 250 tests, 249 passed, 1 failed (an empty-role B2 checkpoint identity regression); after narrowing the hash input, affected B2/source/chunk tests passed: 34 tests, 311 assertions. Final changed-path focused rerun: 12 passed, 63 assertions. Focused cases include authorized and revoked access, deleted documents, stale pipeline IDs, processing denial, hidden formula-cache metadata, legacy textual sources, exact structural roles, XLSX/DOCX table packing, bounded large-table splitting, idempotent provenance, and stale publication fencing.

Full backend suite result: **1,375 tests; 1,374 passed; 9,635 assertions; 0 failures/errors; 1 skipped** (279.1 seconds). Pint `--dirty --test`: **passed**. `git diff --check`: **passed**.

## 11. B1, B2, billing, and parser limitations

B1 ranking/materiality and canonical evidence identity were not changed. B2's checkpoint identity is stricter only when actual value-role anchors exist. Billing, provider reservation, and customer credits were not changed. No live provider calls were made. PDF table, OCR table, geometry, and visual reading-order claims remain unavailable. DOCX/XLSX parser limitations remain as recorded in `structured-extraction-v2-pdf-docx-xlsx.md`.

## 12. Frontend readiness recommendation

Do not build a source-navigation frontend against this endpoint as a final contract yet. First add durable candidate/published processing revisions and version or snapshot the typed intelligence read model, then pin B1/B2 and all source reads to the published revision. Add generation ownership so an older worker cannot publish after a newer run, verify old-or-new reads during publication, and benchmark many-anchor and large-document read latency. The endpoint is suitable for backend integration testing under current Ready documents; it is not yet an atomic revision API.
