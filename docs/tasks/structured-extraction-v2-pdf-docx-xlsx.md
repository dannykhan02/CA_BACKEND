# Structured Extraction V2: PDF, DOCX, XLSX

Date: 2026-10-11. Status: **partial**. This change preserves parser-native source occurrences and their offsets beside the existing extracted-text view. It does not meet every V2 completion gate. No provider call, deployment, push, merge, frontend work, prompt change, ranking change, B1/B2 redesign, or billing change was made.

## 1. Existing extraction inventory and ownership map

| Format | Entry point and library | Existing text | Retained before V2 | Lost before V2 |
|---|---|---|---|---|
| PDF | `ExtractDocumentTextJob` → `DocumentTextExtractor::extractPdfText`, Smalot/PdfParser after `pdfinfo` preflight | `Page::getText()` joined by form feed, then UTF-8 sanitation | Page count and page separator | Parser block identity, geometry, reliable visual reading order, tables |
| DOCX | Job → `DocumentTextExtractor::extractDocxText`, direct WordprocessingML DOM traversal | Paragraph newline; rows newline; cells ` | `; no Word rendering | Text boundaries in the string | XML body/table/row/cell paths, styles, merge metadata, notes |
| XLSX | Job → `SpreadsheetTextExtractor::extract`, PhpSpreadsheet Xlsx reader | Sheet-title line; every iterated row joined by newline; cells joined by ` | `; formula cached value instead of formula text | Sheet title and row order in text | Cell coordinate, type, formula/cache distinction, formatting, merges, visibility, table metadata |

`documents.extracted_text` is the downstream source. The incremental chunker stores raw character ranges and page hints; canonical evidence cites quotes and raw ranges. `TextRoleProvenanceProducer` previously published only textual anchors after evidence merge. Source spans and Phase 3A anchors use Unicode character offsets into the exact stored text, so the compatibility serialization remains the default AI input. The chunker, canonical evidence merger, B1 ranking, Stage A materiality, B2 verifier, semantic.v1 and provenance.v1 were not changed.

## 2. Chosen architecture and structure identity

`StructuredDocument` is a bounded internal result: format, parser version, exact compatibility text, ordered nodes, capability map. Each node has an occurrence ID/path, source type, parent, optional page/sheet, metadata and optional exact `[start,end)` projection into extracted text. Its constructor validates unique IDs, parent existence and every projected substring. `nodesAt()` and `node()` provide reverse and forward lookup. IDs are source paths, never text hashes. The persisted Phase 3A anchor UUID remains the application ID for future navigation.

`structured-extraction.v1` is separate from semantic.v1 and provenance.v1. A parser or policy upgrade that changes paths, text, formatting, offsets, visibility interpretation or projection must use a new parser/policy identity. DOCX body traversal, XLSX sheet order/row/column order and PDF page order are deterministic for the same input. The SourceProvenance parser identity includes the structured policy suffix. There is no generic graph abstraction; the nodes describe the source document only.

## 3. DOCX implementation

The existing safe archive preflight and XML validation still run. A second bounded DOM walk records body order, paragraphs, paragraph style IDs, tables, rows, cells, nested table paths, `gridSpan`, `vMerge` and explicit `w:tblHeader` rows. It excludes `w:del`, `w:moveFrom` and field instructions from body text. The XML text walk is capped at depth 64 and the structural walk at depth 12. Empty nodes are kept only when needed as a container or merge occurrence; no empty paragraph is separately promoted as evidence.

Cell/paragraph spans are aligned monotonically against the established serialization. A cell whose composite text cannot be projected exactly retains a source path without claiming a raw text span; nested paragraphs can still project their own text. Header edges are made only from explicit `w:tblHeader` markup, and `w:val="0"` is treated as false. There is no blanket first-row or first-column rule and no inferred row-header metric binding. Nested cells attach to their nearest native table.

Headers, footers, text boxes, comments, footnotes/endnotes, floating objects, section pagination, accepted tracked-change state, content controls and field-result semantics remain unsupported or partial. Deleted and moved-from text is excluded; inserted text follows body traversal. The previous serializer included `w:moveFrom` text, so documents with that markup may change extracted text on explicit reprocessing. The new parser version identifies that behavior; historical documents are not reprocessed automatically.

## 4. XLSX implementation

PhpSpreadsheet now loads styles as well as values, after the existing ZIP preflight, sheet/row/cell limits and no-formula-evaluation policy. The structure records sheet name/order/visible/hidden/veryHidden, workbook date system, native table ranges and header-row flag, meaningful cell coordinates, raw stored value, cell type, number format, formatted display string where safe, formula text, cached result/presence, merge owner ranges, row visibility, filtered visibility and column visibility. The 1900/1904 calendar is set around formatting and restored afterwards. Numeric serials are not independently interpreted as dates and no timezone is assigned. Error cells remain error values; numeric-looking strings remain strings. Rich text plain content is preserved structurally, while its legacy blank text projection remains unchanged.

The compatibility text still uses cached formula results, with formula instructions absent from AI text. Formula and cache remain distinct in metadata. A cache is source-provided and may be stale; formula cells are not promoted to structural value roles because the current Phase 3A read DTO does not expose cache origin. No formula is evaluated by the reader, no macro is executed, and external links/connections are not fetched. Hidden content stays in compatibility text as before, but hidden/filtered rows, hidden columns and hidden sheets cannot acquire new structural value bindings. This is an explicit compatibility limitation: older AI evidence can still come from hidden content.

Native Excel table metadata, rather than coordinate position alone, permits a same-table value-to-column-header edge. A merged range is stored once on its owner; covered cells do not receive duplicated evidence. A merge is not automatically a semantic header. The parser currently caps merges at 10,000 per sheet and native tables at 1,000 per sheet. The Phase 2A archive and workbook limits remain in force. A sparse sheet with an extreme used row can still hit the existing row/cell preflight or produce many blank compatibility rows; it does not produce a node per blank cell.

## 5. PDF implementation

The `pdfinfo` gate, encrypted/malformed rejection, page cap, Smalot parser and sanitation remain. Each parser page gets a page occurrence, exact global text span and modality `embedded_pdf_text`; form feeds preserve page boundaries. The old `extractPdfText()` entry point remains for workflow adapters. Smalot exposes lower-level text arrays and matrices, but this implementation does not assert that they align reliably with the `Page::getText()` reading sequence. It therefore records no fabricated blocks, visual order, bounding boxes, rotation, table rows or headers. Two-column reading order, invisible/overlaid/accessibility text, annotations, forms, XFA, layers, attachments and signatures are not structurally interpreted. OCR remains a separate textual modality; physical equivalence between embedded and OCR layers is not claimed by text equality.

**Automatic production PDF table facts structurally provable: 0.** PDF table structure remains `PARSER_REQUIRED`.

## 6. Capability matrix

| Capability | DOCX | XLSX | PDF |
|---|---|---|---|
| Exact compatibility text and projection | Paragraph/cell where exact; nested composite cell may be path-only | Populated scalar/cache cell where serialized | Page text |
| Source identity | Body XML path | Sheet order + coordinate | Page number + raw text offsets |
| Native tables | Table/row/cell paths, nested tables | Excel table/range metadata | Unavailable |
| Headers | Explicit `w:tblHeader` column path only | Native table header row only | Unavailable |
| Merges | `gridSpan`/`vMerge` metadata | Native range on owner cell | Unavailable |
| Geometry / visual reading order | Unavailable | Not applicable | Unavailable/unverified |
| Formula/cache/display | Not applicable | Preserved separately; cache not calculated | Not applicable |
| Hidden content | Non-body parts unsupported | Preserved and marked; no new structural role promotion | Visibility unverified |

## 7. Backward-compatible text and projection

The production text serialization remains paragraph/newline/cell separators for DOCX, sheet/title/row/cell separators for XLSX, and form-feed pages for PDF. No new delimiter format is exposed to the AI pipeline, so literal `|`, newline or colon in a cell does not create a new parser-level relationship. A generated XLSX fixture was compared character-for-character with the previous `readDataOnly(true)` serialization; the DOCX and PDF fixtures were compared with their established extraction methods. Observed differences on tested fixtures: **0 characters**. The known `w:moveFrom` change above is intentional and parser-versioned; no corpus-wide difference measurement is available. Every projected node validates against the exact stored text, and reverse lookup can return all nodes covering a range. Unprojectable composite nodes retain weaker path-only capability, never invented offsets.

## 8. Storage, publication and provenance integration

The additive `database/migrations/2026_10_11_000003_create_structured_extractions.php` stores one versioned JSON node payload plus capability map, file-content hash, text hash, parser and policy. This table is necessary because Phase 3A anchors have no fields for formula/cache, number format, workbook date system, visibility or merge metadata. `StructuredExtractionStore` validates the result, locks the document, writes candidate structure, flips one active revision and updates `extracted_text` in **one transaction**. It rejects a changed graph under the same revision identity and reuses identical reprocessing. The new extraction job verifies the source bytes against `file_hash` before structured publication. A failed transaction leaves the previous text/structure revision intact. New rows are workspace/document scoped; active reads also require the current file and text hashes. A partial active structure is never published.

After canonical evidence merge, the existing `TextRoleProvenanceProducer` adds native occurrences and relationships to the **same Phase 3A tables** and keeps textual fallback for evidence without a unique structural value. SourceProvenance publication remains transactional/idempotent; readers require matching text revision, parser policy, workspace, document and active set. The extraction transaction and the later provenance transaction are separate pipeline stages. This is a remaining atomic-revision gap: there is a period with current text/structure but no newly published provenance. The reader will not attach old-revision anchors to new text, but the whole AI/evidence/provenance pipeline is not one database transaction.

## 9. Field-role improvements and connectedness

Actual DOCX and XLSX format fixtures now produce a value cell anchor plus a parser-proven period header anchor, connected to the same native table. Binding requires one cited value-cell candidate; repeated matching cells remain ambiguous. The producer does not bind a metric from the first column, guess a unit from number format, borrow a period from another sheet/table, or promote hidden/formula-cache cells. A value with two numeric subvalues needs a subspan and is left textual. `StructuralProvenanceValidator` still rejects disconnected roles and cycles. `StructuralClaimGuard` still rejects a conflicting period before textual matching. Per-role proof remains partial: a value and period can be structural while the metric stays textual.

**Automatic production DOCX table facts structurally provable: at least 1 partial value+period fixture. Automatic production XLSX table/cell facts structurally provable: at least 1 partial value+period fixture. Fully proven metric+period+value facts from automatic row-header detection: 0 for both.** These are fixture counts, not production field rates.

## 10. Chunking impact

No chunker or AI prompt changed. Existing raw offsets still address the compatibility text. A native table can still be split at a chunk boundary; a large/sparse worksheet can produce many blank compatibility rows. Bounded structure and provenance caps prevent node/edge explosion, but row-aware chunk boundaries and bounded structured AI serialization remain outstanding. This is a reason the V2 completion gate is not met.

## 11. Fallback audit

Existing historical documents remain readable with textual provenance. Native structure unavailable produces path/textual capability only. A cell with ambiguous ownership, hidden state, formula cache or unsupported table header receives no stronger role. DOCX composite cells with no exact projection retain path-only identity. PDF tables, geometry and reading order are explicitly unavailable. If graph publication exceeds 20,000 anchors or 100,000 relationships, the merge records `textual_only` and logs a warning; it does not publish a half graph. A structured extraction/parser/store failure before text publication fails the extraction job and keeps the prior revision. No nearest-text ownership, first-row spreadsheet header, inferred PDF table or fabricated geometry fallback was introduced.

## 12. Adversarial fixtures and tests

Real DOCX OOXML fixtures created by tests cover repeated identical table values, the same value in prose and table, explicit header markup, a merged cell, nested table, body paragraphs before/after tables and table paths. Real XLSX fixtures cover two sheets, repeated value across sheets, native table metadata, another same-sheet table, merge owner, formula, hidden row, hidden column, very-hidden sheet, raw/display percentage, 1904 date system, error/boolean/string/rich-text types. The writer omitted one hidden-row attribute, so that test patches the worksheet OOXML before parsing. The existing five-page PDF fixture verifies page identity and absence of fake tables; 10/100-page copies were used only for local performance measurement. The Phase 3A tests already cover foreign workspace evidence rejection, graph cycles, duplicate occurrences, stale text and role contradiction. Remaining fixture gaps include true cached-formula staleness, many merges, huge shared strings/styles/relationships, PDF two-column/rotation/hybrid OCR, and DOCX notes/revisions/vertical merge continuation assertions.

## 13. Performance measurements

Local PHP CLI, warm filesystem, one process; generated fixtures. Parse includes extraction and projection; graph time is in-memory anchor/edge construction with zero evidence rows, excluding DB publication. Peak memory is cumulative process peak, so only the sparse-case increase is attributable in this run.

| Case | Parse ms | Graph ms | Nodes / anchors / relations | Text chars | Peak process memory |
|---|---:|---:|---:|---:|---:|
| Small DOCX | 2.1 | 0.6 | 9 / 9 / 10 | 43 | 54.5 MB |
| 200 small DOCX tables | 16.5 | 4.5 | 1,201 / 1,201 / 1,600 | 3,000 | 54.5 MB |
| Small XLSX | 16.6 | 0.3 | 5 / 5 / 4 | 55 | 54.5 MB |
| 4,000 populated XLSX cells | 325.9 | 14.7 | 4,001 / 4,001 / 4,000 | 35,664 | 54.5 MB |
| Sparse XLSX, B90000 populated | 974.8 | 0.6 | 3 / 3 / 2 | 360,033 | 152.5 MB (+98 MB) |
| 10-page PDF | 15.3 | 0.1 | 10 / 10 / 0 | 449 | 152.5 MB cumulative |
| 100-page PDF | 28.1 | 0.2 | 100 / 100 / 0 | 4,499 | 152.5 MB cumulative |

Sparse memory growth comes from PhpSpreadsheet and the unchanged 90,000-row text view, not node count. The source producer uses one bounded graph and batched inserts; `SourceProvenanceReader` uses two queries for one evidence-ID batch, with no recursive graph load. Exact DB write count and B1 read query count were **not measured** in this phase. Graph publication remains one potentially large transaction; a dedicated scale gate is needed before broad rollout.

## 14. Storage and migration impact

One JSON row per active/reprocessed revision duplicates some text metadata already represented by Phase 3A anchors, but stores source-native metadata the anchor schema cannot represent. The primary storage risk is large node JSON and an additional read/write during merge. Limits: 100,000 structured nodes, 20,000 provenance anchors, 100,000 provenance relations, DOCX nesting 12/64, XLSX 10,000 merges and 1,000 native tables per sheet, plus all existing archive/sheet/row/cell/page caps. Above the graph cap, structural provenance degrades to textual with an observable merge diagnostic; above the parser node cap, extraction fails rather than silently truncating.

Migration chain, rollback and reapply passed on disposable local PostgreSQL. Deploy order: migrate before new extraction workers. Old workers ignore the new table; new workers on an unmigrated database cannot publish structure. Rollback drops structured JSON revisions and their metadata; it does not undo `extracted_text` or already published Phase 3A anchors. No production migration or historical mass reprocessing occurred. New uploads gain structure automatically. Historical documents keep textual provenance until explicit parser-only reprocessing; that path can avoid AI calls and customer credits, but a dedicated parser-only command is not implemented here.

## 15. B1 impact

No ranking, materiality, source ID, template, selection or credit semantics changed. B1 reads the existing SourceProvenance projection. A valid structural period can reject a conflicting claim; absent structure leaves the Phase 2B path. Focused B1/provenance regressions passed. Exact B1 query count under a large graph remains unmeasured.

## 16. B2 impact

No prompt, narrative candidate selection or verifier rule changed. B2 receives proof through the existing Stage A snapshot and StructuralClaimGuard. A proven wrong period cannot be rescued by a broad textual quote. OCR/PDF remain textual. Provider cost and customer billing paths were untouched.

## 17. Migration and revision limitations

The new file is `database/migrations/2026_10_11_000003_create_structured_extractions.php`. It is additive and uses a partial unique index for one active revision per document. `structured-extraction.v1` and parser versions must change on any representation-affecting upgrade. The source and text hashes prevent cross-revision reads, but the later provenance publication is not co-transactional with extraction, and the canonical evidence graph is not rebuilt by a parser-only operation yet.

## 18. Focused tests

Parser/resource tests: 13 passed, 53 assertions at the first parser gate; later four new structured parser tests passed, 33 assertions. The requested parser, provenance, semantic, B1/B2, pipeline and authorization selection passed **182 tests, 796 assertions**. The affected PDF/OCR workflow selection passed **31 tests, 363 assertions** after restoring the legacy entry point. These selections overlap and are not summed.

## 19. Full suite

Final complete backend suite: **1,363 tests; 1,362 passed; 9,572 assertions; zero failures/errors; one skipped**. The first complete run exposed a mocked PDF entry-point regression (5 failures and 2 errors); the entry point was restored, the affected workflows passed, and this final complete rerun is green.

## 20. Formatting and diff check

Pint on all changed PHP files passed. `git diff --check` passed. No production migration, provider call, deployment, push or merge was run.

## 21. Remaining parser and publication limitations

DOCX notes, headers/footers, revisions, fields and some nested composite projections are partial or unavailable. XLSX hidden content remains in compatibility AI text; cached formulas cannot be structurally promoted until cache provenance is shown to B1/B2. The legacy spreadsheet view still serializes many blank rows and can be memory-heavy. PDF block order, geometry, visibility, table structure and hybrid OCR equivalence remain unproved. The chunker can split tables, the parser-only enrichment lifecycle is not implemented, and full pipeline publication is staged rather than one atomic extraction/evidence/provenance revision. Large ZIP component-specific and adversarial PDF layout fixtures are incomplete. These are completion blockers, not inferred capabilities.

## 22. Frontend readiness recommendation

**Do not build source navigation UI yet.** First add an authorized source-reference DTO over stable Phase 3A anchor IDs, complete the parser-only revision lifecycle, handle hidden spreadsheet evidence policy, and close the text/structure/provenance activation gap. PDF table navigation must remain unavailable until a parser can prove table cells. Final status: `STRUCTURED_EXTRACTION_V2_PARTIAL`.
