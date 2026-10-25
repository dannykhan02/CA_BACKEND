# DocIntel general table intelligence: architecture and categorical verification

Date: 2026-10-25. Status: local implementation through verified categorical bars, default off. No deployment, merge, production reanalysis or paid inference.

## Current architecture and why it is narrow

`DocumentTextExtractor` stores Smalot page text and Unicode offsets. When `DOCINTEL_PDF_TABLE_STRUCTURE` is enabled, `PdfTableGeometryProbe` runs one bounded Poppler `pdftotext -bbox-layout` pass, and `PdfTableStructureProjector` maps candidate words to exact Smalot text. `StructuredExtractionStore` writes revision-scoped nodes and capabilities. `StructuredProvenanceProducer` creates anchors and explicit row/header/table edges; `SourceProvenance` publishes a workspace/document/pipeline/revision-scoped graph. `PdfSourceCandidateCollector` creates separate `pdf-cell:` evidence, and `StructuredPdfFieldProof` requires value, row, dated header path, scale/unit, currency and governing owner. `MetricCollector` and `ChartCandidateBuilder` form observations and series. `VerifiedPdfChartPersister`, `VerifiedPdfChartReadModel`, `ChartResource` and `SourceReferenceResolver` recheck current revision and value anchors before rendering and navigation. Model evidence remains separate.

The original geometry probe admits only at least two distinct dated duration columns and complete numeric rows. The financial verifier then assumes one row metric across dated columns. This is a sound, narrow strategy, not a general table representation. The new basic path retains the same exact projection and storage but does not enter that verifier.

## Coverage matrix

`F` = existing financial verification and chart path; `V` = exact categorical verification and chart path for the stated simple layouts; `S` = exact structural reconstruction/read model only; `P` = partial; `U` = unsupported. `S` does not mean evidence verified or chart ready.

| Structural family | Current coverage | Boundary |
|---|---|---|
| Multiple explicit dated financial columns, native text | F | Same-page, provable header path, unit, owner and comparable observations only. |
| Single-header category/value, category/value/percentage, mixed text/numeric columns | V | One page, 2–6 explicit headers, at least two aligned detail rows, and an unambiguous numeric column with an explicit unit. Text columns remain structural context. |
| Single-period categorical tables | V | Caption and fiscal period are proven where claimed; every plotted category/value pair has separate current source anchors. Vertical bar only. |
| Key/value and descriptive text-only tables | U | No numeric alignment signal; a new structural detector and descriptive verification strategy are needed. |
| Multi-column numeric tables without dated headers | V | A selected column is verified independently when its own header proves an amount, count or supported measure with one unit. Other columns remain separate. |
| Monthly/weekly/daily, long time series, actual/budget/forecast | P | Some wide financial duration headers work; other period/comparator roles are not established. |
| Cross-tabs, matrices and pivot tables | U | Both dimensions and repeated labels need explicit header paths. |
| Hierarchical rows/columns, subtotals and grand totals | P | One explicit reported total is checked and excluded from chart points. Multiple totals, nested groups and multi-level paths are unsupported. |
| Statistical/scientific and medical/lab tables | U | Ranges, reference intervals, uncertainty, significance, detection limits and prescriptions need distinct semantic roles. |
| Operational/business grids | P | Straight native-text grids with explicit currency, count or a small measurement-unit set can be verified as categorical; broader units and row-specific units remain unsupported. |
| Legal/administrative descriptive tables | U | Should become structured presentation, not numerical charts. |
| Borderless and bordered simple native-text grids | P | Alignment can work; borders/shading are not interpreted. |
| Multi-line cells, merged/nested, multipage, rotated, image-only/OCR, sparse layouts | U | Do not report these as reconstructed. Ordinary prose/OCR extraction remains separate. |

The real Northstar fixture now yields its financial grid and a separate operational grid. The Quillmere production PDF was not present locally. A clearly synthetic PDF reproduces its stated single-period cost structure, including the blank basis cell in the total row. It tests the mechanism, not production recall or accuracy.

## Common representation and stage boundaries

The existing `StructuredDocument` graph is the durable table IR: a revision-bound table node has a page and box; explicit header row and data rows own cell nodes; nonblank cells retain raw text, exact Unicode offsets and PDF boxes. Basic tables add `structural_type`, caption ownership, and separate layout, semantic, evidence and analytical status fields. `BasicTableInterpreter` exposes a normalized read view with table ID, page range, caption/period source ID, columns, row/cell IDs, raw text, parsed number, data type, unit, role, aggregate role, source span/box and arithmetic checks. A blank total cell has no invented source span. No new database table is needed for this increment; `structured_extractions` already pins file hash, text hash, parser version and policy. Future hierarchy, merged-cell and cross-page fields can extend this graph with versioned node metadata.

1. **Detection:** Poppler words, line positions and aligned explicit headers yield `layout_candidate_only` regions. A candidate is not proof.
2. **Reconstruction:** basic grids receive ordered row/column cells; the existing financial projector remains unchanged for financial candidates.
3. **Structural validation:** every nonblank basic cell and caption must match one exact Smalot line and valid geometry in column order. Partial rows, swapped columns or unmatched text reject the whole basic table with bounded reason counts.
4. **Semantic interpretation:** column headers give conservative roles: category, currency amount, percentage, comparator or unresolved. A caption fiscal token is retained only when explicit. Raw and parsed values remain separate.
5. **Evidence verification:** `PdfCategoricalCandidateCollector` creates source-derived `pdf-category:` observations; `StructuredProvenanceProducer` binds exact category, value, header, unit and scale anchors. `PdfCategoricalFieldProof` verifies each source node, geometry, row/header/table/caption edge, role binding, owner, revision and published provenance set. `PdfCategoricalFieldVerifier` stores the verdict and fingerprint. This strategy never creates `pdf-cell:` financial facts or asks a model to validate cells.
6. **Analytical eligibility:** `PdfCategoricalChartBuilder` requires a complete 2–30 detail-row series of current proofs, one consistent explicit unit/scale and no duplicate categories, partial layout, conflicting total or mixed status. `VerifiedPdfChartPersister` stores a vertical bar in the existing chart tables; `VerifiedPdfChartReadModel` rebuilds the candidate and compares every persisted point and source reference. `ChartResource` marks `categorical.v1` distinctly, and `SourceReferenceResolver` provides the numeric-cell location plus category/header context. Pie, horizontal bar and other categorical chart types are not enabled.

The shared orchestration should select exactly one strategy from explicit structural roles. Strategy contracts should declare structure, relationships, required anchors, ambiguity rejection and permitted operations:

| Strategy | Proven relationship and required evidence | Ambiguity rejection | Operations after proof |
|---|---|---|---|
| Existing multi-period financial | Same row across dated header paths, printed unit/scale, reporting owner, exact value and current provenance set | Missing/conflicting date, unit, owner, basis or cell path | Comparable time-series chart, current implementation |
| Basic categorical, implemented | Category ↔ amount/count/limited measure per row; exact header, cell, caption/period where claimed, unit and revision owner; total as supporting check | Duplicate labels, mixed units, unknown measure role, omitted rows, conflicting total or source edge | Vertical bar only when every point is independently verified; percentage column is context/check, not an amount series |
| Key/value and descriptive, planned | Key ↔ literal text/value with exact header and cell anchors | Repeated key without scope, merged/continuation ambiguity | Structured table only unless a separate analytical rule applies |
| Cross-tab/matrix, hierarchical, statistical, planned | Both dimensions or full hierarchy and measure kind, with distinct total/range/reference roles | Unowned header, aggregation overlap, confidence interval treated as measure | Appropriate chart or tabular view only after dedicated proof |

This keeps one general source model and a small semantic strategy registry. It avoids feeding unrelated tables to the financial verifier. Competing strategies must resolve by explicit structural role or reject, never by arbitrary first match.

## Accuracy and refusal rules

The basic path rejects unmatched or reordered source text, invalid/overlapping column geometry, swapped boxes, missing non-total cells and ambiguous duplicate lines. A nearby caption that cannot be projected exactly blocks chart eligibility so status or period context cannot silently disappear. Its interpreter declines charts with unresolved units, comparator columns, duplicate categories, multiple totals, conflicting reported totals or mixed forecast/provisional/estimated status. It excludes a reported total from detail values and never derives a missing value from it. Arithmetic consistency supports a relationship but does not independently prove it. It preserves currency symbols as printed; `$` is not an ISO currency. It supports only a small explicit measurement-unit set; mixed row units, ranges, inequalities, thresholds, footnotes, conversion and clinical interpretation remain unresolved. A calculated result or a model response never upgrades structural cells to trusted evidence.

The existing `table_projection_status`, candidate/rejected counts and bounded reason codes distinguish no candidates, rejected candidates and extractor execution failure. Basic read views additionally distinguish structural, semantic, evidence and analytical states. Production diagnostics should aggregate these stage counters without logging document contents. The next stage should publish bounded semantic/evidence/chart rejection codes in the revision API; a parser failure must remain distinct from zero candidates.

## Implementation path and production migration

The first increment adds `PdfTableGeometryProbe::extractAll`, a basic branch in `PdfTableStructureProjector`, `BasicTableInterpreter`, caption provenance, and a financial collector guard. `DOCINTEL_PDF_BASIC_TABLE_STRUCTURE` is default off and works only under the existing default-off `DOCINTEL_PDF_TABLE_STRUCTURE` flag. Financial-only runs retain their parser version and financial candidate route. Basic-enabled runs use an added parser-version suffix even with zero basic tables, so immutable extraction rows are not silently rewritten. Existing documents need **re-extraction** to gain basic nodes; no schema migration or automatic backfill is required. Re-extraction must use current file/pipeline revision ownership and regenerate source provenance. Existing financial observations and charts keep their verifier and read-time gates.

The second increment adds `PdfCategoricalCandidateCollector`, `PdfCategoricalFieldProof`, `PdfCategoricalFieldVerifier` and `PdfCategoricalChartBuilder`. It reuses `StructuredProvenanceProducer`, `SourceProvenance`, `DocumentChart`, `DocumentChartPoint`, `ChartResource` and source navigation with a distinct `categorical.v1` strategy marker. The existing financial verifier and read-time proof remain independent. Existing documents require **re-extraction and reanalysis** under both enabled flags to create categorical observations and charts; no schema migration, automatic backfill or production reprocessing is performed. Next capabilities are descriptive key/value presentation, cross-tab and hierarchy paths, then statistical roles and continuation pages. OCR and rotated layouts require a separate coordinate and confidence policy.

No additional inference call is required by this increment. Basic detection shares the one Poppler pass and adds bounded per-page scanning, extra projected nodes, provenance anchors/edges, seven evidence rows for the synthetic seven-category fixture and one chart with seven chart-point rows. Verification and chart reads add database lookups and deterministic reproof proportional to the number of accepted cells. The synthetic eight-row table produced 47 anchors and 127 edges in a local provenance build; this is a fixture count, not a production storage or latency estimate. A production rollout needs measured document size, stage latency, storage growth and false acceptance on a real multi-domain corpus before enabling either flag broadly.
