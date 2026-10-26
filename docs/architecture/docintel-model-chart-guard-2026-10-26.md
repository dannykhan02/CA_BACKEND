# Model chart rendering guard and offline row-match evaluation

## Production reconstruction diagnosis

The production PDF and its Poppler word lines are not present in this checkout. The reported four projected tables, first rows, and layout statuses establish that candidates were accepted; they do not identify the line that stopped each table. No production-specific root cause is asserted.

`PdfTableGeometryProbe::basicTable` is the only writer of candidate `partial`. It sets `partial=true` only when, after at least two accepted rows, a line within 45 Poppler y units of the last accepted row has at least one numeric-looking word and lacks the first expected cell or has an impermissible missing column. A one-cell blank total is allowed. Other loop exits leave `partial=false`. `PdfTableStructureProjector::projectBasic` maps that flag to `partial_reconstruction` or `structurally_reconstructed` after validating candidate column order, row geometry, exact Smalot text projection, and cell offsets. `BasicTableInterpreter` then maps it to `blocked_partial_reconstruction` or `reconstructed_exact`. Four accepted cells per row do not exclude a partial stop on the *next* line.

The stronger status only means the detector's chosen cells were projected exactly. `PdfTableGeometryProbe::columns` joins adjacent Poppler words when their gap is at most 18 points; `basicTable` then assigns words using header starts and requires only one numeric column shared across rows. It does not prove that every logical printed column was separated. Word grouping is a plausible mechanism for the malformed table's stronger status, but the exact production mechanism and the two partial stops require the original geometry to identify. The status rules were not globally relaxed.

Read-only diagnostic for document `01a1749e-bb72-7182-b6c1-98efc41904cd`, published revision `01a1749e-bb9d-70f8-a471-6fc477a6950d`:

1. Export `parser_version`, `capabilities`, and `nodes` from the active `structured_extractions` row for that document, plus the published revision's source file hash and extracted-text hash. Confirm they match the active row. Inspect table nodes and **all** cells immediately after the last accepted row on pages 4 and 5, including the malformed table.
2. Obtain the exact published PDF bytes read-only and run `pdftotext -f 4 -l 5 -bbox-layout <published.pdf> -` locally. Preserve word text and `xMin/yMin/xMax/yMax` for headers, accepted rows, and the next two visual lines. Compare each word's assigned column to the detector's header starts and its 12-point offset. This identifies whether the stop was a footnote, omitted cell, wrapped row, adjacent table, or genuine defect.
3. Run `BasicTableInterpreter::interpret` against that exact stored `StructuredDocument` and record `caption_ambiguous`, `analytical_status`, columns/units, distinct labels, totals checks, and `potential_charts`. This does not modify production data.

The saved chart records 180 and 181 were not available locally, and the Railway CLI in this workspace returned `Unauthorized` on `railway status`. Their actual types, titles, points, and validator outcomes are therefore unknown. Once read-only production access is available, first confirm the linked Railway project and environment, then run these commands **one at a time** against the production backend:

```sh
railway status
railway ssh --service CA_BACKEND --environment production -- php artisan docintel:model-chart-diagnostic 01a1749e-bb72-7182-b6c1-98efc41904cd 180
railway ssh --service CA_BACKEND --environment production -- php artisan docintel:model-chart-diagnostic 01a1749e-bb72-7182-b6c1-98efc41904cd 181
```

The command checks the current published revision and prints the saved type, title, description, numeric data, source metadata, structural decision, and public API decision. Confirm `published_revision_id` equals `01a1749e-bb9d-70f8-a471-6fc477a6950d`. Add `--match-rows` to either command only when evaluating the experimental matcher. A direct read-only SQL alternative for the stored fields is:

```sql
BEGIN TRANSACTION READ ONLY;
SELECT c.id, c.intelligence_revision_id, c.type, c.title, c.description,
       c.data, c.source_metadata
FROM document_charts AS c
JOIN documents AS d ON d.id = c.document_id
WHERE d.id = '01a1749e-bb72-7182-b6c1-98efc41904cd'
  AND d.published_intelligence_revision_id = '01a1749e-bb9d-70f8-a471-6fc477a6950d'
  AND c.intelligence_revision_id = d.published_intelligence_revision_id
  AND c.id IN (180, 181)
ORDER BY c.id;
COMMIT;
```

Even if the status changes, categorical verification can still reject a table because of ambiguous captions, provisional/forecast language, duplicate first-column labels, missing units or scale, unparsed amounts, inconsistent totals, stale revision, or missing category/value/header/caption source anchors and relationships. The land-use interpreter uses **column 0** as the category, so a chart whose categories are the `Land use` cells in column 1 would not pass that strict path. `Area (m²)` is not currently recognized by its `headerUnit` list, whereas `Area (ha)` and `Amount (TVD)` are. No claim is made that either production chart would pass strict verification after a reconstruction correction.

## Standard rendering behavior

Persisted `model_unverified` bars, time lines, and percentage pies render whenever `ModelChartStructure` accepts their exact saved points. No chart-specific feature flag is read. Duplicate labels, totals mixed with details, non-finite values, inconsistent explicitly stated units, unordered/non-temporal lines, and pies without a positive 100% composition fall back to the existing data table. Negative bar and line values are retained. Origin remains `model_unverified`, evidence status remains `ai_interpreted`, and both verification booleans remain false. The UI keeps the label `AI-interpreted · Not independently verified` on cards and inspection views. No source page is synthesized. CSV and PNG actions remain disabled for model charts. Word exports include the values as a table with the warning and exclude the unverified visualization.

`DOCINTEL_MODEL_CHART_RENDERING` and `DOCINTEL_MODEL_CHART_MATCH_SHADOW` are obsolete and can be deleted from Railway after deployment. The chart API reads neither variable, including when Laravel configuration is cached. `DOCINTEL_PDF_TABLE_STRUCTURE` and `DOCINTEL_PDF_BASIC_TABLE_STRUCTURE` still gate existing PDF extraction and strict verification work and are unchanged. `DOCINTEL_V2_CHARTS` remains a legacy config entry with no chart reader found in this workflow; it was left outside this focused change.

The row matcher is absent from ordinary chart reads. Its only application entry point is the explicit read-only command `php artisan docintel:model-chart-diagnostic <document-id> <chart-id> --match-rows`. Without `--match-rows`, the command reports the stored chart, structural result, and current API eligibility; with it, the command also evaluates projected basic PDF table rows. Neither mode updates data, upgrades evidence, or blocks rendering. The matcher does not overwrite values or make model requests.

The matcher accepts only exact normalized decimal figures in credible projected table regions, checks exact category cell text and geometric row alignment, and refuses a unique result when another label cell intervenes between category and figure. Repeated figures or categories remain ambiguous. Missing geometry yields at most `figure_without_relationship`. A `unique_row_match` means only row proximity and a retained printed figure. It does **not** verify unit, column heading, reporting period, scale, total semantics, or derivation. No `source_figures_located` user label is shipped: that promotion requires independently reviewed accuracy on representative production documents. Existing strict `verified_pdf_table` readers continue to own `source_verified`.

## Fixture evaluation and limits

The deterministic synthetic row benchmark in `ModelChartSafetyTest` has two unambiguous direct pairs, four non-direct/ambiguous probes, and two deliberate contradictions. Unique-row coverage was **2/2**; false positive unique-row matches among the four non-direct probes were **0/4**; contradiction detection was **2/2**. These are fixture outcomes, not production accuracy estimates. API and frontend regression tests cover default rendering of valid bars, time lines and percentage pies, invalid fallback, unverified labels, and verified-PDF isolation.

Unsupported in this first matcher: OCR-only or unprojected tables, split/multi-page tables, wrapped rows, categories assembled from multiple cells, page layouts without reliable row geometry, derived values without an explicit derived marker, and semantic proof of units/columns/periods. Repeated values reduce match coverage intentionally. The detector's 18-point word grouping remains an open reconstruction limitation pending the production word geometry. Before any user-facing `source_figures_located` tier, collect a labeled sample of real PDF tables and measure false positive row matches, correct-match coverage, and contradiction precision by layout and revision.
