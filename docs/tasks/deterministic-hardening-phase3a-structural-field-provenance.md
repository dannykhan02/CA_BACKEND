# DocIntel Phase 3A: structural and field-role provenance

Date: 2026-10-11. Status: **partial**. This working tree contains an additive provenance store, a conservative raw-text role producer, bounded read projection, and structural-verifier gates. Parser-native table producers are not yet connected, so the Phase 3A completion gate is not met. No live provider call, deployment, push, merge, frontend build, pricing, prompt, Stage A ranking, or semantic.v1 change was made.

## 1. Existing provenance and ownership inventory

| Owner | Source information retained before Phase 3A | Limitation |
|---|---|---|
| `documents` | `extracted_text`, `file_hash`, PDF page count, current `ai_pipeline` | Text can be replaced on reprocessing. It is not a layout tree. |
| `document_source_spans`, `EvidenceGrounding`, `SourceSpanBuilder` | Extraction-versioned raw character offsets, page when the PDF page map is complete, ordinal, coarse type and stable span key | `table_row` is a line classification, not a cell/header relation. No geometry. Span key belongs to one extraction version. |
| `document_chunks` | Pipeline key, raw offset range, page range when known, input hash and result | Chunk order is not PDF visual reading order. |
| `document_evidence`, `EvidenceMerger` | Canonical record, source quote, chunk ID, raw start/end, page, optional span ID | A quote can contain several facts. Legacy duplicate quotes choose the first chunk occurrence. Sources are display citations, not role edges. |
| `TypedEvidenceProjector`, `ProvenanceProjector` | Quote-local typed value and document/unknown origin | Phase 2B verifies semantics but has no persisted field anchor graph. |
| `MaterialityReadModel`, B1 `BriefAssembler`/`BriefVerifier` | Record-local typed values and cited `source_id` | Existing citation and ranking remain. The new reader adds proof metadata in one batched Stage A pass. |
| B2 `StageASnapshot`, `NarrativeContextBuilder`, `NumericClaimGrounder`, `NarrativeVerifier` | Same canonical source IDs, restricted context, record-local verification | B2 still receives no source quotes in its prompt. Proven role contradiction now blocks a numeric candidate. |
| `DocumentIntelligenceResource`, `EvidencePageLocator` | Existing quote/source map and page hints | No source-navigation endpoint or highlighting DTO was added. |

`EvidenceMerger` remains the canonical evidence owner. `SourceProvenance` owns additive source graph publication. It never rewrites canonical evidence or the original quote. Raw source offsets use Unicode character indices into the exact stored extraction string; `MatchingTextNormalizer` is used only for content comparison and never to derive offsets.

## 2. Format capability matrix and parser ownership

| Format/path | What currently survives | Phase 3A classification |
|---|---|---|
| Native PDF via Smalot | Per-page flattened text and a reliable page number when the page map is complete; no word boxes or cell hierarchy | `TEXTUAL`; page is available, table/reading order/rotation geometry `PARSER_REQUIRED`. Two-column adjacency is never a structural edge. |
| PDF OCR / image OCR | Page text and confidence; Tesseract consumes TSV but discards word boxes before persistence; Claude vision returns text | `TEXTUAL`; geometry and OCR table structure `PARSER_REQUIRED`. Confidence cannot upgrade capability. Hybrid duplicate regions are unresolved; text equality never merges anchors. |
| DOCX | WordprocessingML body traversal retains paragraph, row and cell separators in flattened text but discards node paths, merges, footnotes and layout semantics | `TEXTUAL`; native table/cell producer `PARSER_REQUIRED`. Headers, footers, text boxes, comments, tracked changes, nested/merged cells and notes are not claimed. |
| XLSX | Sheet-title lines and flattened rows; formula text is not evaluated, but a cached result may be emitted | `TEXTUAL`; sheet/cell coordinates, formula-versus-cache identity, merges, named ranges and visibility policy `PARSER_REQUIRED`. The present extractor does not preserve those identities in evidence. Hidden content is currently not explicitly filtered, so no Phase 3A structural claim is made for it. |
| Visual/chart extraction | Image references, page for some regions, AI chart observations | No parser-proven cell edges. AI output cannot supply deterministic geometry or table ownership. |

The generic store accepts narrow parser-supplied relationships; production currently supplies only raw-text containment. The table fixture proves the contract and verifier path, not automatic table recovery from real DOCX/XLSX/PDF files.

## 3. Storage choice, migrations, and source anchors

Compared designs: (A) adding anchor/role JSON to evidence would keep writes simple but make ownership constraints and bounded role queries difficult; (B) small normalized tables give revision identity, joins, referential ownership, indexable roles and atomic publication; (C) a generic graph database would add unnecessary infrastructure. Chosen: **B**, four narrowly scoped tables in `database/migrations/2026_10_11_000001_create_source_provenance.php`:

- `source_provenance_sets`: workspace/document, extracted-text SHA-256 revision, parser version, `provenance.v1`, pipeline key, graph hash, published/active state.
- `source_anchors`: unique occurrence key within a set, kind, modality, page/sheet/path, optional raw offset pair, exact source text/hash and optional geometry with coordinate-system metadata.
- `source_relationships`: fixed relation vocabulary: `contained_by`, `row_header`, `column_header`, `table_parent`, `section_parent`, `caption`, `footnote`; bounded header ancestry is validated before publication.
- `evidence_role_bindings`: roles `entity`, `subject`, `metric`, `value`, `currency`, `unit`, `scale`, `period`, `qualifier`, `direction`, `numerator`, `denominator`. A role is written only when the input graph actually contains its support.

An anchor's key denotes an occurrence (offset/path/cell), never its text. Two `12.4` cells retain distinct keys and UUIDs. The content hash detects drift but does not replace occurrence identity. A geometry value requires page identity and a declared PDF-point or image-pixel system, dimensions and rotation. No producer currently writes geometry; no screen coordinates are fabricated.

The migration is additive and must precede full use of the new code. A new worker on an unmigrated database catches publication failure and retains textual evidence; old workers ignore the new tables. No historical backfill is required for readability. Historical reprocessing is required to gain any new role proof, and parser-native reprocessing will be required for future table edges. Rollback deletes only Phase 3A provenance and its supporting evidence-owner unique index; it does not delete canonical evidence. Production migration was not run.

Composite foreign keys bind set, anchor, relation and evidence workspace/document ownership. Unique keys prevent duplicate occurrences, relationships and role bindings. Checks reject self edges, invalid relation/role/kind/modality names, partial/negative offsets and geometry without a page. Application validation rejects disconnected table roles, cycles and ambiguous multi-value cell binding. A transaction locks the document, validates the current extracted-text revision, creates a candidate graph and sets it active only after all rows pass. Failed candidates roll back; readers require `published` and `active`. Replays with the same graph hash return the same set. A changed graph under the same revision/parser/policy raises an error rather than silently changing old anchor meaning. A new parser version publishes a distinct set and atomically switches active sets.

## 4. Role proof, table proof, and fallback

`TextRoleProvenanceProducer` runs after the incremental evidence merge. It binds only unique literal raw-source occurrences within one cited quote. It does not use normalized indices. Duplicate values in one quote, duplicate legacy quotes, or flattened table rows retain citations but receive no guessed role edges. Currency and scale subspans are taken only from the unique value spelling. XLSX produces no lexical role upgrade because its cell structure was flattened. `SourceProvenanceReader` gives old records `TEXTUAL`; it returns per-role anchor details and separate structural capability for new sets, scoped by workspace, document, current source revision, pipeline and active policy.

The structural graph accepts an explicit value-to-row-header and value-to-column-header path inside the same table. A table-to-caption edge can provide a unit/scale from that table. Same-text anchors in another table do not count. Multi-row headers use bounded ancestry, not nearest text. A cell with multiple numbers must provide a distinct value subspan. The graph records caption/footnote relationships without interpreting restatement or accounting meaning. Partial roles remain partial in the returned capability map. A table source with no recovered relation stays textual.

`StructuralClaimGuard` is called by both B1's `BriefVerifier` and B2's `NumericClaimGrounder`. When a value has a bound period, a contradictory year in the claim rejects that record before textual matching. Other Phase 2B gates still run. A record with no value binding uses the existing Phase 2B textual path. A proven contradiction has no textual rescue. The source graph publisher also checks key canonical fields against bound source text. These checks are deliberately narrow; full metric/row verbal equivalence and table-wide currency semantics are not inferred.

Fallback audit: truthful textual provenance when no structure exists is **B, compatibility/visibility**. A source drift, duplicate value, invalid graph or foreign workspace role fails closed. Nearest header, nearest period, cross-record field copy and invented geometry are **C, unsafe masking**, and no such fallback was introduced. Incremental merge logs provenance publication failure by exception class and continues with the existing textual evidence. This avoids turning an optional provenance build into a customer extraction failure, but requires monitoring before rollout.

## 5. B1, B2, policy and source navigation

B1 ordering, top-six selection, Stage A materiality and templates were not changed. Stage A now batch-loads role bindings; B1 source IDs and old quote/page fields remain. The reader loads all Stage A evidence in batches of 500 and two queries per batch, avoiding N+1, but it still loads more proof than the final B1 findings need. A selection-scoped read is a remaining optimization.

B2 prompt and context payload are unchanged. B2 verification can see proof on the in-memory Stage A record; `NarrativeContextBuilder::inputHash` includes separate `provenance.v1` alongside unchanged `semantic.v1` **only when supplied records carry value-role proof**. Textual-only documents retain their Phase 2B attempt identity. This prevents reuse of a persisted verdict across provenance-rule changes without invalidating unrelated extraction or textual B2 checkpoints. An explicitly authorized future synthesis job on a structurally proven document may need a new provider attempt; GET remains read-only. No verifier version or prompt was bumped.

Future navigation should resolve an authorized evidence/source ID to a safe DTO containing stable anchor IDs, page/sheet, raw range, cell identity and bounded related header IDs. The current reader is internal; it is not a public DTO or endpoint. It does not return filesystem paths, storage keys, parser commands or secrets. Authorization must be checked before a later endpoint resolves any reference.

## 6. Adversarial matrix and measured boundaries

| Case | Result |
|---|---|
| Simple table value, row, column; table-level USD millions | Parser-supplied graph fixture publishes and reads all roles. |
| Wrong row, wrong column, cross-table header, caption from unrelated table | Graph validator rejects disconnected/wrong paths in fixtures; period contradiction guard rejects before textual rescue. |
| Identical text in two cells; repeated prose number | Distinct occurrence anchors; repeated prose value remains textual. |
| Multi-level header | Explicit path accepted to bounded depth. |
| Parent cycle, self/duplicate relation, foreign evidence | Rejected by validator, uniqueness/checks or composite ownership. |
| Unicode ligature, decomposed accent, NBSP, typographic punctuation | Raw-source role offset tested; normalized matching never supplies an offset. |
| Same revision replay, new parser revision, source-text replacement, failed publication | Idempotent set reuse; active graph switch; stale proof hidden; failed candidate rolled back. |
| Multiple numeric values in one cell | Whole-cell value binding rejected; parser must provide subspan. |
| Merged cells, repeated headers, subtotals, continuation/multi-page tables, footnote markers, nested groups | Contract can represent explicit paths, spans and footnote edges; automatic producer/semantics `PARSER_REQUIRED` or `PARTIALLY_SUPPORTED`. No continuation is inferred by page adjacency. |
| Two-column/rotated/hybrid PDF, OCR geometry and uncertain OCR tables | `PARSER_REQUIRED`; no structural upgrade or geometry produced. |
| XLSX formula/cache, hidden content, merged ranges, named ranges | `PARSER_REQUIRED`; no formula evaluation was added. Hidden content remains an extractor policy gap. |

Fixture safety counts: zero observed wrong-row, wrong-column, cross-table, cross-workspace, invented-anchor, invented-geometry or stale-revision accepts in the included tests. These are fixture results, not field rates. Usefulness: one parser-supplied valid table fact with row, column and scale is provable; prose roles are newly bound when literal occurrences are unique. Automatic production table facts newly provable: **zero**, because format-native table producers are not yet connected. Legacy B1 source IDs lost in focused tests: zero. B2 supported claims lost in focused tests: zero. Full production-format coverage is not measured.

Storage: a raw prose fact with one quote and value/metric/period generally writes one set per document plus up to four anchor rows, three containment edges and three bindings; overlapping occurrences deduplicate within a set. The small table fixture writes five anchors, six relations and four bindings. The graph hash and unique occurrence key prevent retry growth. Read projection uses chunked binding and relation queries with no per-record query; it does not recursively load a document graph. The producer currently builds a document-size in-memory graph and publication is one transaction; very large document stress and selected-finding-only reads remain unmeasured.

## 7. Verification and remaining completion boundary

Focused source-occurrence/structural tests: **14 passed, 35 assertions**. Focused B2 checkpoint/API regression tests after the conditional policy change: **60 passed, 1,127 assertions**. Earlier focused semantic, provenance, B1/B2, parser and incremental regression selection: **165 passed, 734 assertions**; groups overlap and are not summed. Final complete backend suite: **1,357 tests; 1,356 passed; 9,528 assertions; zero failures/errors; one skipped**. Pint on changed PHP passed; `git diff --check` passed. Full local migration chain passed. The Phase 3A migration rollback and forward reapply both passed on the disposable local PostgreSQL test database. No production migration was run.

**Next boundary before frontend:** preserve native DOCX table/cell paths and XLSX sheet/cell coordinates, formula/cache and visibility flags through extraction; establish only parser-proven row/column/header paths; add a bounded, authorized source-navigation DTO; then verify B1/B2 against real format fixtures and large documents. PDF and OCR remain textual until their parsers yield reliable regions and table relations. No temporal, legal-modality, coverage, frontend or generic graph work is part of this boundary.

Final status: `DETERMINISTIC_HARDENING_PHASE3A_PARTIAL`.
