# Phase 3A implementation and remaining gate — 2026-10-12

Branch: `feat/docintel-phase3a-structural-provenance`, based on clean stabilization commit `d1edf1e`. Acceptance cases were recorded in `phase3a-acceptance-2026-10-12.md` before implementation. This report describes the current branch, not a production deployment. **Phase 3A is not closed.** Mandatory PDF/OCR/hybrid and several field roles still lack supported implementation or ground-truth verification.

## Baseline and corrections

The baseline already had revision-scoped source sets, source anchors and role bindings, a parser-native DOCX/XLSX projection, PDF page anchors, Tesseract TSV word boxes in OCR metadata, a bounded source-reference API, and B1/B2 structural claim gates. Deficiencies found in that implementation:

- A table value was selected when its citation merely overlapped a cell; substring matching could bind `2.4` to `12.4`. There was no row-header producer and DOCX used physical cell ordinals rather than grid columns for merged cells.
- A structurally located value could carry an unproven period, metric, or unit and still pass the structural guard. Unit claim contradictions were not checked.
- DOCX heading styles, footnote references, and vertical-merge label ownership were not projected. XLSX merged-header ownership was recorded but not used for native table headers.
- Every native node was published as an anchor. A 20,000-node document failed provenance publication even if only one row was cited.
- OCR word boxes were persisted in `ocr_results.metadata` but not exposed as source anchors. The PDF modality check used the existence of any OCR row, including stale text.
- Cross-page proof had no explicit continuation vocabulary and did not require continuation for a header on another page.

Implemented on this branch: complete cited-cell containment and exact folded cell-value equality; bounded citation-scoped graphs with real parents/headers and table row/column anchors; DOCX grid-span and vertical-merge row ownership, heading-style sections, and referenced footnote nodes; XLSX merged native-table headers; explicit column-header units; source-field absence and currency-claim gates; a typed, adjacent-page continuation relation; Tesseract word geometry in exact, current OCR source references; and stale OCR/native channel separation. Source text remains the exact compatibility serializer. Producer/parser versions were advanced so a changed graph cannot silently reuse an old version's identity.

### Modified-file inventory and additive migration

| Files | Change |
|---|---|
| `app/Services/DocumentTextExtractor.php` | DOCX headings/footnotes/grid columns and PDF empty-page diagnostic; existing extracted text serialization preserved. |
| `app/Services/Extraction/SpreadsheetTextExtractor.php`, `config/document_processing.php` | Native XLSX structural-node ceiling with explicit partial capability while retaining full extracted text. |
| `app/Services/Intelligence/StructuredProvenanceProducer.php`, `TextRoleProvenanceProducer.php` | Exact cited-cell ownership, native row/column/header graph, bounded publication, OCR word location and channel check. |
| `app/Services/Intelligence/StructuralProvenanceValidator.php`, `StructuralClaimGuard.php`, `SourceProvenance.php` | Cross-page/field checks and allowed native structural kinds/continuation. |
| `app/Services/Intelligence/SourceReferenceResolver.php` | Bounded geometry field and continuation relation in authorized source DTO. |
| `database/migrations/2026_10_12_000001_allow_source_continuations.php` | Additively widens only check constraints to allow `continuation`, `table_row`, `table_column`. No existing data or canonical evidence is rewritten. Down migration refuses to discard present proof. |
| `tests/Feature/StructuredProvenanceIntegrationTest.php`, `tests/Unit/Services/{DocumentTextExtractorTest,StructuredExtractionTest}.php`, `tests/Unit/StructuralProvenanceValidatorTest.php`, `tests/Benchmarks/phase3a_large_xlsx.php` | Positive/adversarial native, OCR, ownership, ambiguity and scale cases. |
| `docs/tasks/phase3a-acceptance-2026-10-12.md`, this report | Pre-implementation criteria and measured completion boundary. |

The source-reference contract retains `sourceId`, revision, `matchState`, quote/range, roles and bounded relationships. Each role anchor can now return `geometry` with `image_pixels_top_left`, page, image dimensions, and an exact Tesseract box when the OCR text revision matches. Native table paths expose sheet/cell, table-row and table-column identities through role and relation anchors. A source without proven structure remains `TEXTUAL`; a published revision continues to resolve through its pinned provenance set. No external filesystem path or parser command is exposed.

## Capability and verification matrix

| Requirement | Status | Verified support and boundary |
|---|---|---|
| Document/revision identity, raw offsets, channel, source API | Implemented and verified | Existing ownership and revision contracts plus current integration/API tests. Anchor IDs remain stable inside a published set; old published revisions pin their set. |
| DOCX paragraphs, `Heading1`–`Heading6` section ancestry | Implemented for defined structures | Body-level style hierarchy and parser paths. Custom heading styles, text boxes, tracked changes, headers and footers are not interpreted. |
| DOCX nested tables, horizontal/vertical merges, explicit column headers and row labels | Implemented for defined structures | WordprocessingML `tblHeader`, `gridSpan`, `vMerge`, and table paths. Row labels require an explicit top-left label (`Metric`, `Item`, `Line item`, `Account`, `Description`, `Indicator`). No guessed header from an unmarked table. |
| DOCX footnotes | Implemented for defined structures | Referenced `word/footnotes.xml` notes retain source path/text and a reference edge. Body text stays unchanged. Footnote semantics and unsupported endnotes are not verified field roles. |
| XLSX sheets, native table ranges, cells, merged one-row headers | Implemented for defined structures | Native addresses, table ownership, exact header column, merged owner, and explicit row-label heading. Untagged visual ranges do not become tables. |
| XLSX formula/cache, hidden content | Implemented with documented limits | Formula text and cached result are distinct metadata; formula values and hidden rows/columns/sheets cannot become structural value proof. The legacy text serializer still includes cached/hidden content, so downstream textual evidence is not eliminated. |
| PDF page identity and embedded text | Implemented for defined structures | Smalot page count/text and empty-page diagnostics; parser order remains unverified. PDF table/row/column/heading/coordinates are not claimed. |
| OCR word location | Implemented for defined structures | Current Tesseract page text must exactly equal stored extraction; valid boxes become image-pixel source anchors. Missing/invalid boxes stay textual. Claude vision has no boxes. |
| Explicit continuation contract and cross-page rejection | Implemented, insufficiently producer-tested | Adjacent, same-table continuation is accepted only when supplied as an explicit edge; uncontinued cross-page role borrowing is rejected. No current PDF/DOCX producer establishes multi-page table continuation. |
| Value, metric, subject, period, unit roles | Partially implemented | Native table field binding requires the exact cited cell and explicit row/column path. Other applicable roles may remain unbound; a structured numeric claim with a nonempty but unbound canonical field fails its structural gate. |
| Currency, scale, denominator, action/object, conditions, qualifications | Partially implemented / missing | Existing store vocabulary and raw-text lexical roles cover some cases. There is no general format-native denominator/percentage-basis or action/object/qualification producer. The canonical extraction schema does not expose all as separate fields. |
| Rotated scan, multi-column OCR, scanned tables | Missing | Tesseract TSV provides word boxes but does not establish reading order, orientation, table cells or headers. These inputs remain textual and structurally uncertain. |
| Mixed native/OCR PDF pages and conflicting values | Missing | The current job OCRs only after wholly empty native extraction. It does not combine channels page by page. Stale OCR cannot relabel current native text, but missing scanned pages are not recovered. |
| Captions/titles and table-level footnote semantics | Partially implemented | Store accepts explicit caption/footnote edges. Native producers do not infer a table caption or value qualifier from layout adjacency. |
| Large reports | Implemented for tested scale, partial overall | 6,001-row, 24,006-node native-table benchmark gives a 16-anchor proof graph. Beyond the 100,000-node ceiling, XLSX retains full text and marks structural nodes partial. The 120-second extraction-job budget remains unverified at maximum configured XLSX size. |

## Parser and alternative assessment

| Path | Accuracy evidence | Resource/deployment tradeoff | Decision |
|---|---|---|---|
| Smalot `Page::getText()` / `getDataTm()` | Page text and text-object origins exist; no reliable glyph widths, cell boundaries, header roles or visual reading-order contract. | Already deployed, low incremental cost. | Preserve page-only structural claim. Never fabricate PDF table cells or exact boxes. |
| Poppler `pdftotext -bbox-layout` | A local five-page synthetic fixture completed in 0.01 s and 18,856 KB peak process memory. It emits word boxes, not table ownership, and has not been aligned against Smalot offsets on a ground-truth corpus. | Poppler tools already support `pdfinfo`/rasterization, but another per-document process adds latency and deployment version coupling. | Do not publish PDF table/header proof from geometric proximity. Evaluate exact word alignment and explicit tagged-table sources separately. |
| PDF table heuristics via Python libraries / visual model | Can find candidate regions, but column/header relations are heuristic and unverified on this corpus. | New Python/OpenCV/ML dependency, memory and worker/runtime maintenance costs. | Not justified for verified evidence until a representative benchmark proves accuracy and bounded costs. |
| DOCX WordprocessingML direct traversal | Explicit style, table, merge and footnote relationships with exact source paths. | Existing ZipArchive/DOM dependency; bounded traversal. | Implemented. |
| XLSX PhpSpreadsheet native table collection | Explicit table ranges and cells, merge/visibility/formula metadata. | Existing dependency; in-memory workbook load is the main cost. | Implemented for native tables, with cited-node proof graph. |
| Tesseract TSV / hOCR / OSD | TSV word boxes are auditable; none establishes table ownership. OSD/rotation and column order were not benchmarked on deterministic rotated/multi-column ground truth. | Additional OCR passes risk the 60-second per-page provider timeout and image memory budget. | Keep word geometry, with unverified reading order/table status. Evaluate rotation and layout strategies before accepting numeric structure. |

The current hybrid job path was also inspected directly. `ExtractDocumentTextJob` dispatches downstream analysis as soon as any native PDF text exists. `OcrPageBatchJob` assumes it owns every OCR page in sequence and its finalizer replaces `extracted_text` with OCR-only text. Reusing it for selected blank pages would discard native pages, misnumber page checkpoints, and change OCR admission/accounting. A safe alternative needs a generation-owned page manifest, page-selective raster/OCR batches, an assembler that preserves each page's channel and offset map, and explicit conflict states. That is a bounded job redesign, not a one-line fallback; no such change was applied without corresponding hybrid ground truth and recovery tests.

## Ground-truth results and measurement scope

The native integration corpus asserts **7/7 supported native facts** (XLSX simple, units, merged header, exact repeated cell; DOCX simple, horizontal merge, vertical merge) and **9/9 adversarial native associations withheld** (other-sheet period, other-table period, substring value, wrong DOCX period, wrong XLSX unit, hidden row, formula cache, ambiguous repeated value, token-prefix year). OCR separately asserts 1/1 valid word location and 2/2 adverse box/channel cases; the continuation unit fixture accepts 1/1 explicit adjacent relation and rejects 2/2 missing/nonadjacent cases. The scale benchmark adds one supported late-row fact. Across these asserted cases, **incorrect structural acceptance = 0** and **supported fact rejection/loss = 0**. These are fixture counts, not estimates of real-document accuracy. The deterministic large workbook took 5.15 s wall time and 108,003,328 bytes peak PHP memory in one run; it is below the 120-second extraction-job budget at that size. No maximum-size claim is made.

Focused native/validator and source-reference tests, the complete backend suite, migration up/down/reapply, and changed-file formatting passed. Final backend suite: **1,420 tests; 1,419 passed, one skipped, zero failures/errors; 9,847 assertions**. The full local migration chain and the additive migration's rollback/reapply passed in a second disposable database. Tests used local PostgreSQL 18 and mocked/no AI providers. No production database, paid provider, deployment, push, merge, or Revision V1 activation was involved.

Compatibility evidence: the final suite includes normal analysis, incremental processing, evidence-span pipeline, candidate/published revision, generation ownership, legacy brief, matter/published-revision, search isolation, usage, and failed-job/recovery tests. The source-reference API tests passed after its additive geometry field. Saved-comparison and export paths were not directly modified; the existing suite exercises report snapshots and comparison-related reads, but there is no new format-specific export fixture in this work. This is a remaining rollout test, not a claim of complete export coverage.

## Remaining completion plan and user consequences

1. Build authorized rotated scan, multi-column, scanned-table and mixed native/OCR PDFs with exact page/word/cell ground truth. Compare Tesseract OSD plus bounded image rotation, Poppler word alignment, and candidate table engines for accuracy, memory and latency. Only publish relationships that survive exact source alignment and adversarial header/row checks.
2. Add page-selective OCR to the extraction job with channel-preserving page assembly and explicit conflict resolution. Until then, a PDF with native text on some pages and scans on others can omit scanned-page facts.
3. Establish PDF table semantics from source tags or a demonstrated high-precision parser; add multi-page continuation producer evidence. Until then, PDF/OCR table numbers remain textual candidates and must not be called structurally verified.
4. Extend canonical field support for percentage denominator, qualifiers, action/object, caption/footnote meaning and formula-cache freshness only with source-backed acceptance tests. Untagged spreadsheet ranges, custom DOCX styles, and unsupported footnotes remain uncertain.
5. Run maximum configured XLSX/DOCX/PDF resource tests and an authorized production-like sample. Publish the additive continuation and row/column-kind constraints, deploy worker code, monitor provenance publication failures and source-reference states, then reprocess selected historical documents. Old documents remain readable without backfill, but gain no new proof until reprocessed. Production migration/deployment requires separate rollout authorization.

Phase 3A completion criterion remains unmet while the mandatory missing cases above are unimplemented or unverified.
