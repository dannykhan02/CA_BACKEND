# DocIntel Phase 3 final implementation and closure review

Date: 2026-10-12. Branch: `feat/docintel-phase3a-structural-provenance`. **Recommendation: NO-GO for formal Phase 3 closure.** This review covers the original upstream-provenance objective and the actual branch implementation. It does not require universal PDF or OCR understanding, but mandatory supported rotated, multicolumn, scanned-table, and field-role cases still have no positive ground-truth proof. The current code deliberately leaves those relationships uncertain.

## 1. Implementation summary and commits

The starting branch was clean at `5065d0f`; it already contained Phase 3A native DOCX/XLSX proof, page-only PDF projection, OCR word boxes, and the bounded source-reference API from `4f39ef5` and `57514c8`. The stabilization base is `d1edf1e`. This work builds on all of them:

- `51e730e` — page-selective OCR for PDF pages with no native text, a revision-aware native/OCR page assembler, real page-number checkpoints, retry-safe finalization, per-page channel and word-box provenance, bounded same-context conflict marking, assertion-force checks, and source-quality DTO fields. No paid provider call was made.
- This report is committed separately after the code.

Native PDF pages are preserved in their original order and offset space; scanned blank pages are inserted at their actual page numbers. The assembler refuses OCR for a page with native text or a page outside the parser manifest. A missing OCR checkpoint prevents final publication. OCR remains text and word-location evidence, never table proof. Equal-context conflicting metric records remain separate; their `source_conflict` metadata blocks selection as one verified figure in B1 and blocks a numeric claim in B1/B2. The checker cannot decide which value is true.

## 2. Modified files and migrations

Implementation: `app/Jobs/{ExtractDocumentTextJob,OcrPageBatchJob}.php`; `app/Services/Extraction/PdfHybridAssembler.php`; `app/Services/AI/Incremental/EvidenceMerger.php`; `app/Services/Intelligence/{AssertionForceGuard,SourceReferenceResolver,StructuralClaimGuard,StructuredProvenanceProducer,TextRoleProvenanceProducer}.php`; `app/Services/Intelligence/Brief/{BriefAssembler,BriefVerifier,KeyFigureSelector}.php`; `config/document_processing.php`.

Tests: `tests/Feature/{IncrementalDocumentPipelineTest,SourceReferenceApiTest,StructuredProvenanceIntegrationTest}.php`; `tests/Unit/{AssertionForceGuardTest,IntelligenceBriefVerifierTest}.php`; `tests/Unit/Services/PdfHybridAssemblerTest.php`. No new migration was needed: conflict status stays in canonical evidence JSON and source quality is an additive response field. Production still needs the **unapplied, existing** Phase 3A migrations `2026_10_11_000001_create_source_provenance.php` and `2026_10_12_000001_allow_source_continuations.php` before these workers are rolled out. The migration sequence was verified in the preceding Phase 3A report; it was not rerun against production.

## 3. Parser strategy, dependencies, and supported structures

| Format/path | Supported relationship | Boundary |
|---|---|---|
| PDF native, Smalot parser | Exact extracted-text offsets and parser page number; blank-page manifest | Smalot flattens content. It does not provide reliable table ownership, row/column/header coverage, captions, continuation, glyph boxes, or visual reading order. Page-only proof stays textual. |
| PDF hybrid | Native pages plus OCR text for **fully blank native-text pages** in one ordered revision, each with its own channel | Native text mixed with a scanned region on the same page is not recovered. Page adjacency creates no table continuation. |
| OCR, existing Tesseract TSV | Text, confidence, bounded exact word boxes when the current page text matches the OCR checkpoint | Rotation, multicolumn reading order, scanned cell/header ownership, uncertain glyphs and layout remain unverified. Claude vision has no word boxes. |
| DOCX WordprocessingML | Existing explicit heading/section, nested table, grid-span, vertical-merge, marked header, row label and referenced footnote paths | Custom styles, text boxes, tracked changes, unmarked table headers and semantic footnote qualifiers remain uncertain. |
| XLSX PhpSpreadsheet | Existing native table range, sheet/cell identity, merged one-row header, formula/cache and hidden-content metadata | Untagged visual ranges and hidden/formula cached values do not become verified structural values. |

The installed Laravel/Railway stack already includes Smalot, PhpSpreadsheet, Poppler (`pdftoppm`, `pdftotext`), Tesseract and PHP GD. No dependency was added. Poppler bounding boxes can locate words but do not identify table ownership; geometric row or header inference was not admitted. A Python table engine or vision model would add resource and maintenance cost without verified high-precision relationships in this corpus. This is a demonstrated parser-evidence limit for the current implementation, **not** a claim that PDF tables cannot be parsed in general.

## 4. Ground-truth acceptance and incorrect admissions

Each case below had an expected relationship before evaluation. Counts refer only to the named deterministic fixture assertions; they are not estimates of document-wide extraction accuracy.

| Fixture group | Expected supported relationships | Expected uncertain/rejected associations | Observed |
|---|---:|---:|---|
| Existing native DOCX/XLSX integration corpus (two years, repeated numbers, adjacent units/tables, merges, hidden/formula cells) | 7 value/header facts | 9 adversarial associations | 7/7 retained; 9/9 withheld. |
| Existing OCR geometry and continuation checks | 1 exact OCR word box; 1 explicit adjacent continuation | 2 bad OCR box/channel cases; 2 missing/nonadjacent continuation cases | All asserted outcomes passed. No PDF continuation producer exists. |
| New three-page hybrid assembly and job finalization | Native page 1, OCR page 2, native page 3; exact offsets, page identity, retry stability and channel identity | OCR overwrite of native page, invented page, scanned table upgrade | Supported pages retained; 2/2 invalid page insertions rejected; OCR table capability stayed unavailable. |
| New equal-context metric conflict | Two 2024 observations with distinct source offsets remain separate; 2023 observation remains independent | Silent winner; cross-period conflict | Both 2024 records marked unresolved; 2023 unmarked. |
| New obligation and edition guard | Matching `shall ... unless` and matching restated wording | `may`→`shall`, `shall not`→`shall`, dropped exception, original↔restated, actual↔estimated | Supported wording accepted; all five asserted reversals rejected. |
| New source-reference quality | Current structural channel and conflict state; historical reference remains unresolved | Fabricated historical structural/locale proof | Additive quality contract passed. |

**Incorrect structural associations accepted as verified: 0 in the defined adversarial corpus. Supported positive fixture relationships rejected: 0 in the defined supported corpus.** Unverified rotated scans, multiple columns, scanned table cells, same-page native/OCR conflicts and PDF table continuations have **no mandatory positive result**; they are absent coverage, not passing zero-error cases. Repeated numeric occurrences remain ambiguous unless one exact citation and source role resolve them. The hybrid path does not merge duplicate OCR/native passages because it OCRs only blank native pages.

## 5. Performance, limits, and regression compatibility

The existing deterministic 6,001-row XLSX benchmark was rerun: 24,006 source nodes, 16 published proof anchors, 46 relations, **7.582 seconds** wall time and **108,003,328 bytes** peak PHP memory. It is below the extraction job's 120-second timeout for this fixture; maximum configured workbook and hybrid PDF raster/OCR end-to-end budgets remain unmeasured. The page assembler itself is bounded by the existing PDF page manifest; OCR is still capped by configured page admission, three-page batches, 60-second rasterization, 90-second OCR batch job and image-pixel ceiling. A three-page batch containing slow OCR calls can still reach its job timeout; no worst-case claim is made.

Final applicable backend run on an isolated local PostgreSQL 18 database with mocked providers: **1,429 tests; 1,428 passed; one skipped; 9,887 assertions; zero failures/errors**. Focused new/affected provenance run: **94 passed, 498 assertions**. Changed PHP passed Pint, and `git diff --check` passed. The full run includes Document Intelligence V2, Revision V1 ownership/publication, incremental processing, Matters, comparisons, search, exports, billing and legacy-document tests. This verifies regression compatibility in the test suite, not a production rollout. No production database, deployment, merge, push, paid AI call, or Revision V1 activation occurred.

## 6. Requirement closure matrix

| Requirement | Implementation | Tests | Limitations | Closure status |
|---|---|---|---|---|
| Revision-aware source identity, exact quote offsets, authorized source references | Existing provenance store plus additive quality DTO | Source-reference, revision and full backend suite | Historical proof remains unknown until reprocessed | Complete and verified |
| DOCX nested/merged table, marked header, section and footnote source paths | Existing Phase 3A producers retained | Native positive/adversarial corpus; full suite | Only explicit supported WordprocessingML structures | Complete for defined supported structures |
| XLSX sheet/cell, native table, merged header, visibility and formula/cache origin | Existing Phase 3A producers retained | Native positive/adversarial corpus; scale benchmark | Untagged ranges and hidden/formula values are not structural proof | Complete for defined supported structures |
| PDF page identity, text location and safe missing-structure state | Smalot page manifest and raw offsets | Five-page parser fixture; hybrid tests | No reliable cell or glyph/reading-order projection | Complete for defined supported structures |
| PDF tables, boundaries, rows, columns, headers, captions | No verified producer; stays textual | No mandatory positive PDF table fixture | Current parser does not expose those relationships; alternative high-precision parser unverified | Not implemented |
| Cross-page table continuation | Store/validator accepts only explicit adjacent same-table edges | Positive edge and missing/nonadjacent rejection fixtures | No PDF/DOCX source producer emits a verified continuation | Partially complete |
| Blank-page PDF hybrid native/OCR assembly, version/page/channel preservation | Page-selective OCR and assembler | Positive three-page, retry, exact-channel and invalid-page fixtures | Same-page mixed regions, OCR/native duplicates and disagreements unhandled | Complete for defined supported structures |
| Rotated pages, multicolumn order and scanned-table structure | OCR text/word boxes only; no inferred cell or order proof | Existing box tests; no rotated/multicolumn/scanned-table positive corpus | Orientation/layout/table detector unverified under resource budget | Partially complete |
| Repeated occurrences and missing positional metadata | Exact occurrence requirement; uncertain textual fallback | Repeated-value and invalid-box cases | Text-only ambiguity is not resolved | Complete for defined supported structures |
| Value, metric, subject, period, currency/unit and quotation roles in one source context | Native table and unique raw-text roles; structural guard | Native corpus, verifier tests | Applicable unbound fields reject structural claims; not every format has producer | Partially complete |
| Percentage basis/denominator, action/object, conditions/exceptions and table footnote meaning | Existing typed semantic checks plus new narrow assertion-force guard | Modal/exception/edition unit cases | No general canonical field-role schema/producer for these roles | Partially complete |
| Conflict, restatement and OCR disagreement | Bounded same-wording, same-context metric conflicts; source-quality state; B1/B2 numeric guard | Two-value/other-year fixture; idempotent scale regression | Different wording can evade detection; no OCR/native same-page comparison; no winner is chosen | Partially complete |
| Source quality, revision/channel/location/ambiguity/status | Additive `quality` DTO and existing roles/geometry | Current, conflicted and legacy source-reference tests | Language/locale unavailable; OCR glyph uncertainty is coarse | Partially complete |
| Resource limits and full compatibility | Existing limits retained; one scale fixture and full suite | 7.582-second benchmark, 1,429-test suite | Maximum configured PDF/OCR end-to-end budget unverified | Partially complete |

## 7. Remaining implementation needed for closure

The smallest remaining Phase 3 work is bounded to these gates, rather than a new architecture:

1. **PDF/OCR fixture gate:** add authorized, deterministic tagged or parser-verifiable table PDFs plus rotated scan, multicolumn, scanned table, and same-page hybrid fixtures with expected word/cell/header/page relationships. Benchmark Smalot/Poppler/Tesseract and a candidate table or orientation parser in the Railway image under the configured limits. Publish only relationships proven by exact source alignment; classify every other case as textual/uncertain. Do not force a PDF table producer if no tested source representation supports it.
2. **OCR layout gate:** implement bounded orientation and reading-order handling where fixtures verify it, and scanned cell/header ownership only for a demonstrated high-precision class. Extend page assembly for selected same-page regions with an explicit native/OCR duplicate and disagreement state; retain both channels on unresolved conflicts.
3. **Field-role gate:** add backward-compatible role vocabulary and canonical fields for denominator/basis, action/object, condition/exception and edition, then bind each to exact source occurrences and validate same-context ownership. Keep absent fields unknown for historical records and do not derive table-wide qualifiers from adjacency.
4. **Continuation and performance gate:** add an explicit producer only for source-declared multi-page table identity, and test positive and adversarial continuation. Measure extraction plus raster/OCR at the configured page/image limits and verify timeout/recovery without lost native text.

Each gate needs positive and adversarial assertions. Closure may then be **Complete for defined supported structures** with documented unsupported layouts; universal PDF/OCR understanding remains outside the contract. The current missing positive gates prevent a defensible GO decision.

## 8. Production and Revision V1

Apply the existing additive provenance migrations before deploying new workers, then roll out compatible code and monitor publication failures, OCR checkpoints, and unresolved source states. Historical canonical evidence remains readable but must be reprocessed to gain new source proof. The source-reference contract retains source IDs, revision, quote/range, roles and bounded relationships for future annotations; the Notes system was not implemented. Candidate and published Revision V1 ownership remains enforced by the existing revision check on extraction, OCR page assembly and publication. Revision V1 was not activated.
