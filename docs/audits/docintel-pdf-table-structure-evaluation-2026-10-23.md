# DocIntel PDF table structure evaluation — 2026-10-23

## Decision

**Keep `DOCINTEL_PDF_TABLE_STRUCTURE` off by default pending rollout review.** Phase 2 now derives metric, period, scale, currency symbol, and reporting-owner proof from the published PDF graph. The original 17-page Nasdaq PDF produced six eligible benchmark observations, three comparison charts, and six persisted chart points in a disposable local database. Each point retains a page-8 source anchor. The source proves dollar notation (`$`), but does **not** establish the ISO code `USD`; a `USD millions` claim is rejected. No production inference, database write, deployment, paid API or billing change was made.

The code is a narrow, default-off PDF path. Smalot remains the prose extractor and fallback. This branch also closes the pre-existing incremental owner-check bypass in evidence assessment, metric collection and KPI API output.

## Inputs and reproducibility

- Original public document: [Nasdaq Q3 2026 earnings release](https://ir.nasdaq.com/static-files/83ca6992-6374-4358-838f-402a1d65daf4), supplied locally by the user after the investor-relations download returned HTTP 403. SHA-256: `011ca88fabd30dd4cb3a27587221811feb69a8f0869a46a2cdef892c975177b2`; 17 pages, 713,707 bytes. The committed one-page fixture is page 8 of that exact PDF, produced with `pdfseparate`; SHA-256 `cece469e3d5b2bc932a503c11253ea893ac8dad2640d21f83c4d1e362fb36c66`.
- Independent financial fixture: `tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf`, SHA-256 `4507eb76cb2dfce4be55c23e6572a70e473a1bab520071734065f64755384f2f`.
- Adversarial fixture: `tests/Fixtures/financial_layout_adversarial.ps` and its PDF. It includes a valid quarter grid, a nine-month heading over misleading Q3 labels, repeated conflicting Q3 headers and a parenthesized negative.
- Phase 2 fixtures: `financial_units_adversarial.pdf` (conflicting USD scale words, SHA-256 `19a69c74a8ac6a3c8a52276a98cd166e7830487fc0f2ed4321402701e1b6424b`), `financial_units_thousands.pdf` (unambiguous USD thousands, `cdfd48a8207ad4634614826bc20189135ff06f5dc20adc1fdf165f9ad9e440bd`), and `financial_basis_adversarial.pdf` (separate GAAP/non-GAAP rows, `6096c48febfd0a8ac929a9d26b3fd18d00a85636132eb4189281a9d117a4417b`). The corresponding PostScript sources are committed for deterministic regeneration.
- Disposable PostgreSQL 18 was created under `/tmp/docintel-pdf-pg`. A temporary migration directory initially omitted seven Power BI named files. The existing status-check-only migration was then applied locally to support an unrelated restricted-document test; the six access, role, view and RLS migrations remained omitted. No Power BI role or access was provisioned. Feature tests used that schema without running `migrate:fresh`.

## Tier 0 falsification

| Review hypothesis | Result | Evidence |
|---|---|---|
| PDF structured extraction contains only page nodes | **Confirmed for the existing path** | The full Nasdaq PDF produced 17 `page` nodes and zero `table`/`cell` nodes with `smalot-page.v2`. The default-off test preserves that result. |
| Period bindings were absent | **Confirmed locally; production count not queried** | Six Nasdaq benchmark observations cited their exact table rows. The pre-change binding rule produced `value` and literal `metric` bindings, with zero `period` or `unit` bindings. The user-reported production role distribution also had no period role, but no production DB query was made. |
| Normalized period/unit claims fail literal binding | **Confirmed** | The source header is `Three Months Ended` + `September 30,` + `2026`; the canonical claim is `Q3 2026`. The source caption states `(in millions, except per share amounts)`, while the test claim was `USD millions`. `SourceProvenance::publish` also rejects a role whose canonical field is absent from its bound anchor text. |
| The server evidence resolution flag changes incremental verification | **Falsified locally** | The same six stored observations returned `verified=0, rejected=6` before and after toggling the flag in the isolated test. Its call sites are on the normal route. |
| XLSX/DOCX eligibility differs from PDF | **Partially confirmed** | Existing DOCX/XLSX structured provenance tests passed. Their native cells/header edges work. Historical production chart counts by type were not queried, and positive DOCX/XLSX chart rates remain unmeasured. |
| Nasdaq has usable word geometry | **Confirmed** | `pdftotext -bbox-layout` gave separate x ranges for the four income-statement columns. Geometry and smalot row text mapped exactly for the three benchmark metric pairs on page 8. |
| `verify()` is read-only | **Falsified** | `IncrementalMetricFieldVerifier::verify()` writes `source_fields_verified`, revision, policy and grounding verdict to `DocumentEvidence`. The rerun was confined to a rolled-back local test transaction. |
| The incremental route enforces reporting ownership | **Falsified in prior code; fixed on this branch** | `MeasurementIntegrity`, `MetricCollector` and `KpiResource` previously set `ownership_enforced` only for the normal route. An additional classification gap ignored the financial `label` when `concept` was absent. Ownerless financial evidence now stays ineligible on both routes. |

The review's claim that table structure is impossible for *any* PDF applies to the old extractor, not to a layout-preserving source. The review's proposed proximity bridge was not used: a nearby text row is insufficient to assert a column relationship.

## Extractor comparison

| Candidate | Licensing and SaaS fit | Accuracy and coordinates | Deployment, cost and maintenance |
|---|---|---|---|
| Smalot (current) | LGPL-3.0 per `composer.lock` | Retains prose text; no dependable table cells or word boxes in this pipeline | Already deployed; keep as fallback |
| Poppler `pdftotext -bbox-layout` (selected for this experiment) | Installed utility is GPL-2-or-3 per local package notice. Server-side subprocess use has no per-document fee; distribution obligations need review if the container is shipped outside hosted SaaS | Supplies block, line and word boxes. Reconstructed Nasdaq's clean income statement and Northstar's quarter grid. It supplies no table semantics by itself | Already in Docker and CI, one bounded subprocess, no Python service; grid heuristics and text projection require ongoing maintenance. [Utility manual](https://dyn.manpages.debian.org/trixie/poppler-utils/pdftotext.1) |
| `pdfplumber` | MIT, including its `pdfminer.six` base; suitable candidate for proprietary use subject to dependency notices | Native line/rectangle and inferred text-table support; likely better for ruled and irregular tables, **not benchmarked here** | Adds Python packaging and subprocess isolation, but no API fee. Maintained upstream. [Project and table methods](https://github.com/jsvine/pdfplumber), [license](https://github.com/jsvine/pdfplumber/blob/stable/LICENSE.txt) |
| PyMuPDF | AGPL or paid commercial license; not selected for this proprietary SaaS | Strong geometry/table API, **not benchmarked here** | New paid license or AGPL obligations, plus Python dependency. [Vendor licensing](https://pymupdf.io/licensing) |

None of these native-text tools reads a scanned table without OCR. The existing Tesseract path can supply word boxes, but this prototype does not yet form table cells from OCR boxes. There is no measured AI-token saving: extracted prose and the incremental prompts remain unchanged.

## What the prototype reconstructs

The geometry probe groups words by y position, identifies unambiguous period columns and duration spans, then assigns numeric tokens by x alignment. The projector accepts a candidate only when its cells and header fragments map onto the **exact** smalot text revision. It emits `table`, `row`, `cell` and heading nodes with page, bounding box and character offsets. The provenance producer emits `row_header`, `column_header` and `table_parent` only for complete projected rows. It stores PDF-point geometry on anchors. The original text and prose behavior remain unchanged.

On page 8, the header paths are distinct:

| Column | Source path | Canonical period |
|---|---|---|
| 1 | `Three Months Ended` → `September 30,` → `2026` | Q3 2026 |
| 2 | `Three Months Ended` → `September 30,` → `2025` | Q3 2025 |
| 3 | `Nine Months Ended` → `September 30,` → `2026` | 9M 2026 |
| 4 | `Nine Months Ended` → `September 30,` → `2025` | 9M 2025 |

`PeriodParser` now gives quarterly and cumulative headings different granularities. The source path remains available verbatim; the canonical label is derived through that single parser. Projection rejects a changed canonical period or a moved spanning-header path.

The consolidated income statement yielded 32 complete numeric rows and 128 numeric cells with row and column edges in the full in-memory graph. The isolated incremental test cited three rows, persisted edges for their 12 numeric cells, and bound the six tested values. Exact benchmark pairs were:

| Source row | Q3 2025 | Q3 2026 |
|---|---:|---:|
| Revenues less transaction-based expenses | 1,315 | 1,481 |
| Total operating expenses | 729 | 749 |
| Operating income | 586 | 732 |

The PDF caption states “in millions”; it does not spell out `USD` in that heading. Dollar signs elsewhere in the table do not, under the existing measurement parser, prove the ISO currency for every row. The heading names `Nasdaq, Inc.`, but that candidate entity has not been converted into the revision-pinned `governing_owner` proof required for a financial observation. The statement title implies a consolidated report; it does not by itself establish an independently verified GAAP/non-GAAP basis for every row.

## Before and after benchmark

Measured on the original 17-page file on this machine. Extraction timings are one sequential wall-clock run each, including Laravel bootstrap and smalot; they are not production latency forecasts. `Not measured` means the experiment did not run that comparable stage. The isolated incremental test used six cited observations and rolled back its transaction.

| Measurement | Existing pipeline | New default-off path when opted in |
|---|---:|---:|
| Correctly reconstructed tables, full PDF | 0 | 3 projected from 6 geometry candidates; income statement page 8 correct |
| Source-linked numeric financial cells, six-citation local test | 0 | 12 persisted with row/column edges; 128 on page 8 in complete in-memory graph |
| Verified metric observations, six-citation local test | 0/6 | 0/6 |
| Analytically eligible observations, six-citation local test | 0/6 | 0/6 |
| Valid chart candidates, six-citation local test | 0 | 0 |
| Incorrectly accepted observations, six-citation local test | 0/6 | 0/6; all six were rejected, so this is **not** a false-positive rate estimate |
| Extraction duration, full PDF | 2.71 s wall; 2.63 s user CPU | 3.05 s wall; 2.95 s user CPU |
| Peak resident memory, full PDF | 79,176 KiB | 94,944 KiB |

The user-reported production baseline was 193 KPI observations, zero eligible and zero charts; it was not independently re-queried and is not numerically comparable to the six-observation local test. On the independent Northstar PDF, the probe reconstructed one four-row financial table (12 numeric cells). The nonfinancial five-page fixture yielded zero tables.

## Phase 1 blocker, before semantic verification

The actual incremental path was exercised locally: structured extraction → `StructuredExtractionStore` → `TextRoleProvenanceProducer`/`StructuredProvenanceProducer` → `SourceProvenanceReader` → `IncrementalMetricFieldVerifier` → `MetricCollector` → `ChartCandidateBuilder`. The source graph passed structural validation and the six values plus exact row labels bound. Canonical periods and units did not bind to any single verbatim anchor, so the field verifier rejected all six. The owner gate also correctly requires a governing-entity proof; a page heading alone is not that proof. No observation reached chart eligibility, and no chart row was inserted.

Phase 2 addressed the header path, scale, currency marker, and owner gaps described here. The source still does not prove the ISO code USD or an independent alias from “Revenues less transaction-based expenses” to “Net revenue.” The chart therefore retains the source row label and dollar symbol.

## Verification and limitations

- Focused unit tests: 79 passed, 369 assertions in the final unit run.
- Local feature tests: the final combined run passed 57 tests and 414 assertions across chart, materiality, structured DOCX/XLSX provenance, Nasdaq incremental safety, provenance publication and owner proof. An earlier chart/materiality run passed 15 of 16 because the deliberately limited local schema lacked a status-check-only migration; applying that one local migration resolved the fixture failure. No access-related Power BI migration was run.
- Adversarial coverage currently checks a Q3 period reassignment, an x-incompatible spanning header, nine-month/Q3 separation, repeated conflicting headers, parenthesized negatives, and no financial table in a nonfinancial PDF. **Not yet independently covered:** thousands versus millions across a bound unit path, GAAP/non-GAAP identity conflicts in a verified series, wrong business segment ownership, adjacent unrelated columns beyond the strict x-alignment fixture, scanned pages, rotated pages, merged row labels, and continued tables without repeated headers. The flag must remain off until these are tested through the full verifier.
- Geometry candidates on pages 11–13 of the full Nasdaq report did not all project onto exact smalot lines. Their omission is deliberate: no graph edge is emitted for an unmatched row. The page-8 statement is complete for the benchmark pairs; other rows with dashes or wrapped labels may be omitted.
- Poppler word coordinates are PDF points and are converted to the bottom-left geometry convention of `SourceProvenance`. No OCR table geometry is yet emitted. `PdfHybridAssembler` preserves native table nodes and shifts their offsets when another page is filled by OCR.

No production flag, migration, inference route, credit/billing component or deployment was changed. The work is committed only to the dedicated feature branch.

## Phase 2 — source-backed semantic verification

The opt-in path now reads published `row_header`, ordered `column_header`, `table_parent`, caption, and section-heading edges from the **same provenance set** as the bound numeric cell. It checks the workspace, document, pipeline, source hash, exact text offsets, table path, row parent, numeric column, and extraction version. `PeriodParser` receives the original header fragments in `path_order`; it alone derives `Q3 2026` or `9M 2026`. A second or contradictory year edge invalidates the path. The canonical period is never used as a surrogate source quote.

Scale comes from one applicable table caption. A per-share row uses the caption's explicit exception and scale 1. Currency remains separate. An ISO code in an applicable heading can prove that code; repeated `$/€/£` markers can prove their **printed symbol**, not an assumed ISO code. Symbol inheritance requires the same symbol beside every numeric column in at least two separated complete rows, no conflicting symbol in the projected table, exact source offsets and geometry, and column-specific provenance edges. Nasdaq meets this bounded dollar-symbol rule. `USD millions` is rejected on that PDF; `$ millions` is accepted and can be displayed by the chart builder as `$ billion` after exact numeric rescaling. The point still stores its original source value and `$ millions` source unit.

The table-to-entity heading edge supplies an owner candidate. `GoverningOwnerProof` checks its exact named organization, page, table/row/value chain, claimed subject, source revision, extraction version, and provenance set. A blank model subject can be filled from that verified heading; a conflicting claimed subject is rejected. The original blank is retained as `raw_subject`. The owner proof is stored with the same source-span roles required by `MeasurementIntegrity`. A row that names a segment without a financial metric label does not become a consolidated company metric. The default route and the PDF route use the same owner gate. Accounting basis is a separate `accounting_basis` identity: explicit GAAP/non-GAAP row text can establish it, while a claim with no matching source marker fails. The existing `value_basis` remains reserved for actual/target/budget and related performance status.

Only exact source row labels receive a metric role. The Nasdaq “Net revenue” alias remains unresolved because the extracted income-statement row says “Revenues less transaction-based expenses.” The verified chart uses that row label. Unsupported aliases are rejected, rather than accepted by semantic similarity.

`VerifiedPdfChartPersister` runs from the existing incremental merge job only while the new PDF flag is on. It consumes `MetricCollector` and `ChartCandidateBuilder` output, requires current verified evidence from the same provenance set, and persists only defensible time-series candidates. Reruns replace only charts tagged `verified_pdf_table`; a failed or stale rerun retracts those charts. Other chart records are untouched. Generated chart JSON and each persisted point carry the evidence ID, value-anchor ID, page and offsets for navigation. The prose extractor and smalot fallback remain unchanged.

### Same six-observation local comparison

The same six source-row claims used the source-supported unit `$ millions` in both columns below. The baseline is the default page-only pipeline; the opted-in column uses the same PDF and observations. These are **local fixture measurements**, not a reprocessing of the 193 production KPIs. “Numeric cells” counts projected page-8 table cells; “source-linked” counts cells retained by the six cited rows. The Phase 1 `USD millions` fixture remains a deliberate rejection case even with Phase 2 enabled.

| Measurement | Default page-only | Phase 2 opt-in |
|---|---:|---:|
| Projected numeric cells, page-8 income statement | 0 | 128 |
| Source-linked numeric cells for six cited rows | 0 | 12 |
| Bound value roles | 6/6 | 6/6 |
| Bound metric roles | 6/6 | 6/6 |
| Verified period paths | 0/6 | 6/6 |
| Verified scale and currency-symbol units | 0/6 | 6/6 |
| Verified reporting owners | 0/6 | 6/6 |
| Analytically eligible observations | 0/6 | 6/6 |
| Valid chart candidates | 0 | 3 |
| Persisted charts / points | 0 / 0 | 3 / 6 |
| Incorrectly accepted benchmark observations | 0 | 0 |
| Failed verification reason | 6 `unit_source_mismatch` | None |

The page-8 benchmark chart sources in the **full original PDF** are below. Offsets are Unicode characters in its exact stored extraction revision; boxes are Poppler page-point coordinates in top-left order. The source revision SHA-256 is calculated from the extracted text at publication, and the chart stores the provenance set ID and value-anchor IDs. The input PDF SHA-256 is recorded above.

| Source row | Q3 2025 value, offset, box | Q3 2026 value, offset, box |
|---|---|---|
| Revenues less transaction-based expenses | `1,315`, 21609–21614, `[385.39,261.1562,410.35972,272.2616]` | `1,481`, 21600–21605, `[322.39,261.1562,347.35972,272.2616]` |
| Total operating expenses | `729`, 22227–22230, `[393.79,419.4562,410.36768,430.5616]` | `749`, 22220–22223, `[330.79,419.4562,347.36768,430.5616]` |
| Operating income | `586`, 22276–22279, `[393.79,433.6162,410.36768,444.7216]` | `732`, 22269–22272, `[330.79,433.6162,347.36768,444.7216]` |

The independent synthetic financial PDF with a `USD thousands` heading also verified two observations and produced one chart with two points. Changing one claim to `USD millions` removed that series. A separate conflicting-heading PDF (`USD millions and thousands`) was rejected. These fixtures exercise the same extractor and verifier; they do not stand in for a broad corpus accuracy evaluation.

The final focused regression run passed 88 unit tests (403 assertions) and 70 isolated-database feature tests (686 assertions). Three additional incremental merge-job regressions passed (28 assertions) with mocked provider responses. The feature run included the full original Nasdaq PDF through extraction, graph publication, field verification, collection, candidate building and chart/point persistence. The test creates no production records and rolls its transaction back. The chart persistence service is called by `MergeDocumentEvidenceJob` only for an opted-in PDF; no paid provider call is part of this path.

### Adversarial results and remaining limits

The isolated tests rejected all exercised false claims: a Q3 2025/Q3 2026 swap, 9M/Q3 confusion, thousands/millions confusion, unsupported USD on the Nasdaq source, a different company, a Market Services scope claim on a consolidated row, the actual Market Services segment row claimed as consolidated, a sign change, a numeric edit after verification, unsupported “Net revenue” alias, unsupported non-GAAP claim, per-share scaling as millions, a conflicting scale caption, a duplicated year edge, an adjacent-column year edge, a nine-month spanning-header edge, and stale extracted text. Explicit GAAP and non-GAAP rows in an independent PDF verified as two separate chart series; changing a GAAP row's claimed accounting basis was rejected. Header geometry omissions drop the affected row or table. None of these tests accepted a false observation. This is a finite adversarial set, not a measured false-positive rate.

Unresolved limits: the original Nasdaq source does not establish ISO `USD`; its chart must retain `$`. Segment labels embedded inside longer financial row labels and company aliases still need broader corpus tests. Continued tables without repeated headings, rotated or scanned pages, OCR table geometry, irregular merged headers, wrapped row labels and footnoted unit exceptions remain outside this native-text prototype. The marker-inheritance rule is intentionally conservative and may reject valid financial tables with fewer printed symbols. Extraction CPU time and peak memory were measured in Phase 1 above; Phase 2's verifier and persistence steps were exercised in local tests but not benchmarked separately, so no latency or AI-cost reduction is claimed. The PDF feature flag remains default-off for that reason.

### Flag-off ownership impact

The Phase 1 ownership fix in commit `c677912` is **not** guarded by `DOCINTEL_PDF_TABLE_STRUCTURE`. `MeasurementIntegrity`, `MetricCollector` and `KpiResource` now require a governing owner for company financial claims on existing incremental documents even when PDF table extraction is off. A previously chartable, ownerless incremental claim can therefore become ineligible or display a limitation. This is a deliberate fail-closed security correction with release compatibility impact; the default-off regression asserts the same owner rejection in the collector and KPI API. The correction is also isolated for review on local branch `fix/docintel-incremental-ownership-review` at commits `72b5c07` and `7b3a363`, based on the parent of Phase 1. No branch was merged or deployed, and no production flag was changed automatically.

## Phase 3 — automatic source candidates and authentic replay limit

**Decision: keep the PDF flag off.** This phase proves automatic chart generation from projected page-8 income-statement cells, but it cannot establish the requested authentic Nasdaq inference replay: the original production response and the full 17-page PDF are not present in this checkout or its local test storage. A search of saved local JSON responses, fixture files and the disposable local test database found no Nasdaq response containing the reported model labels. The production figures (193 KPIs, zero eligible, zero charts) remain user-reported historical evidence, not a Phase 3 replay denominator. The committed page-8 PDF is the exact income-statement page from the original document (hash above), so its whole statement can be exercised, but page-8 results do not measure the other 16 pages.

The closest available **authentic** frozen provider response is `storage/framework/testing/docintel-v10-evaluation/live-20261021-204955-e0085f8755c8.json.provider-response.json` (SHA-256 `622cca54e2ea149e36ada8fb3d177d93ea00613cc853f204d42d83bf023de6aa`). It has 20 Northstar V10 KPI observations and belongs to the **normal** inference route, with `E...` source-span references, not to the Nasdaq incremental PDF route. It is useful evidence that a frozen model response exists locally; substituting it for the missing Nasdaq response or converting its citations into Nasdaq cell references would not be an authentic Nasdaq replay. No paid inference was called. The Northstar PDF itself was evaluated independently below.

### A. Source-aligned benchmark (six prepared observations)

The Phase 2 result remains **6/6 verified and eligible, 3 chart candidates, 3 persisted charts and 6 navigable points** when the six manually source-aligned observations use the exact three row labels and `$ millions` unit. It is a controlled verifier benchmark. It is not an estimate of production chart yield and was not counted in the automatic source-candidate run below.

### B. Authentic Nasdaq inference replay (unmodified original observations)

| Required measurement | Phase 3 result |
|---|---|
| Original observations replayed | **Unavailable: 0 original records recovered**. The denominator of the original response is not independently known here. |
| Original observations eligible with PDF structure | **Not measurable**. |
| Chart candidates / persisted charts / navigable points from original observations | **Not measurable**. |
| Rejection counts for the original response | **Not measurable**. The examples below are adversarial stand-ins, not the original records. |

The smallest defect exposed by the reported original wording is the gap between model claims and the source path: `Net revenue` is not the printed row `Revenues less transaction-based expenses`; `GAAP operating expenses` is neither the exact row `Total operating expenses` nor an explicitly proven GAAP basis; `US$ millions` is more specific than the printed `$` notation and `(in millions)` caption. The verifier correctly rejects those unsupported identities. The new qualified-dollar check rejects `US$ millions` when the table proves only `$ millions`, so the currency qualifier cannot collapse silently to `$`. The existing value, period, unit and entity mutations also fail verification; a changed claim loses its stored fingerprint and eligibility. No original model field is rewritten to make it pass.

### Separate automatic source-derived path

`PdfSourceCandidateCollector` now runs only for an opted-in structured PDF, after incremental model evidence is merged and before provenance publication. It creates **separate** evidence records from each projected numeric cell. Each record retains the exact row label, numeric text, multirow header path, caption, entity heading and cell ID in `source_representation`, plus a distinct `canonical_representation` for the parsed period and source-supported unit. Its primary `period` field remains the verbatim header path so the existing verifier and chart parser can both rederive the canonical period without changing the shared parser. Existing model records are untouched. Stable cell IDs make reruns idempotent; absent cells are pruned from this source-candidate namespace. The normal provenance producer, `StructuredPdfFieldProof`, owner gate, `MetricCollector`, chart builder and `VerifiedPdfChartPersister` still decide acceptance. No proximity binding or weaker verification rule was added.

An additional merge-job integration test starts the incremental pipeline with the committed PDF page, publishes its structured extraction, completes extraction chunks with **empty mocked model records**, and runs the real merge job. It produces 128 source candidate records and 11 persisted charts with no HTTP provider request. This establishes that the wired job can chart directly from source structure even when the model supplies no accepted observations; it does not replace the missing authentic Nasdaq inference replay.

The page-8 income statement has 32 complete numeric rows and 128 projected cells. This test deleted no model data and did not copy source labels or units into a model record. Its six extra **synthetic** claims used exact source-row labels but the unsupported `US$ millions` unit; all six were rejected with `currency_source_mismatch` and were excluded from the source-only counts below. They are not a substitute for the original response.

| Automatic page-8 source-derived measure | Result |
|---|---:|
| Extracted numeric cells / candidate facts | 128 / 128 |
| Candidates with a source-matched structural value path | 128 |
| Verified / analytically eligible observations | 44 / 44 |
| Chartable series candidates | 11 |
| Persisted charts / navigable points | 11 / 22 |
| Distinct verification outcomes | 44 verified; 84 `governing_owner_unverified` |

All 22 persisted points were checked against their exact value-anchor text and page-1 navigation reference in the committed single-page fixture (page 8 in the original release). The 44 eligible cells represent 11 rows across four columns; the chart builder keeps quarterly and nine-month periods separate and selected the two-quarter comparison for each chart. The 84 owner failures occur on rows whose company-level financial ownership cannot be safely established from the current row/heading rule, including segment and other ambiguous rows. These failures were not changed into accepted company facts. The automatic 11-chart result is a source-derived outcome and must **not** be added to the six-observation benchmark or presented as the authentic inference result.

| Independent PDF fixture | Extracted / source-matched | Verified / eligible | Chart candidates | Persisted charts / points | Distinct rejection reasons |
|---|---:|---:|---:|---:|---|
| Northstar board review | 12 / 10 | 0 / 0 | 0 | 0 / 0 | 7 owner, 3 unit, 2 value |
| Explicit `USD thousands` | 6 / 6 | 6 / 6 | 3 | 3 / 6 | None |
| Conflicting scale heading | 6 / 6 | 0 / 0 | 0 | 0 / 0 | 6 `unit_source_mismatch` |
| Explicit GAAP and non-GAAP rows | 8 / 8 | 8 / 8 | 4 | 4 / 8 | None; bases remain separate |
| Conflicting layout/header fixture | 0 / 0 | 0 / 0 | 0 | 0 / 0 | No complete cells projected; no candidate asserted |

The Nasdaq source exercises a real three-level column path (`Three/Nine Months Ended` → `September 30` → year). Its source-derived Q3 and nine-month facts stay distinct. Existing adversarial tests additionally reject quarter swaps, changed signs and values, missing scale proof, unsupported `USD`/`US$` currency claims, wrong company or segment, unsupported GAAP/non-GAAP identities, source alias claims, changed extraction revision and tampered header edges. The new cases explicitly include `Net revenue`, `GAAP operating expenses`, `US$ millions`, and a GAAP basis claim on an unmarked row. **False acceptances among these exercised adversarial claims: 0.** This is a finite test set, not a corpus false-acceptance estimate. Source-backed recovery is safe for the printed row, period and `$` scale as separate facts; a `Net revenue` alias would require explicit source terminology or a separately reviewed accounting identity rule. The arithmetic `2,393 - 623 - 289 = 1,481` corroborates the printed value, but does not by itself prove that the source names the result “Net revenue,” so no alias was added.

### Release compatibility and verification

The owner correction is independently isolated on `fix/docintel-incremental-ownership-review`: `72b5c07` changes owner enforcement and its provenance regression, and `7b3a363` asserts the flag-off behavior. Because it closes an incremental owner bypass even when PDF tables are disabled, it should receive a **separate safety release review** rather than be merged merely to enable PDF charts. Its release can make previously accepted ownerless financial claims ineligible; this is an explicit compatibility change. This phase did not merge either branch or deploy. The new source-candidate call is PDF-type and feature-flag guarded; the shared period parser remains unchanged. The qualified-dollar rejection runs only inside structured PDF proof. The default-off path therefore has no new Phase 3 behavior beyond the already documented ownership correction.

Local verification: 48 focused unit tests passed (160 assertions); the PDF safety suite passed 15 tests (467 assertions; the optional full-PDF test skipped because that file is absent); 87 provenance and incremental pipeline feature tests passed (458 assertions), including the new opt-in merge-job test. Tests used a disposable local PostgreSQL 18 database, mocked provider calls where applicable and rolled back feature transactions. No production database, production secret, paid AI call, deployment or billing path was used.

## Phase 4 — generalization and release readiness (2026-10-23 to 2026-10-24)

### Decision and scope

**NO-GO for activation, including an opt-in customer beta.** The source-cell path remains useful on the single Nasdaq statement page and on controlled fixtures, but it did not produce an eligible observation from any of the three newly downloaded, independently structured, real issuer PDFs. The PDF flag remains disabled by default. This is a coverage and owner-context failure, not evidence that the verifier should become permissive. There was no production inference replay, production query, provider call, production write, deployment, or billing change.

The offline, rollback-only evaluation can be repeated with `bin/docintel-pdf-source-audit.php` and a disposable local PostgreSQL database. It emits per-document input hash, page and table counts, candidate and rejection counts, chart titles, publication size, and stage timings. Its independent geometry probe is a **second pass** used only to count pre-projection candidates; do not add that timing to the pipeline timing. The corpus output in this review is from a local PostgreSQL 18 cluster with the safe migration set and no Power BI access migrations. Every audit transaction was rolled back. The first two inputs are committed fixtures; the other three financial PDFs were downloaded from the issuers' public sites to `/tmp` and are identified by URL and hash below. The five-page negative control is a committed, simple nonfinancial fixture. The three additional `financial_*` PDFs are synthetic adversarial controls and are not counted as independent real issuers.

### Original Nasdaq artifact and replay status

The required original document ID is `01a16829-dfd8-72bb-b82f-d6abd2cf34d7`, and its PDF hash is `011ca88fabd30dd4cb3a27587221811feb69a8f0869a46a2cdef892c975177b2`. After the initial checkout/`/tmp` scan and issuer endpoint HTTP 403, a wider search recovered `/home/collins/Downloads/Telegram Desktop/3Q26 Earnings Press Release - FINAL.pdf`: **the complete 17-page, 713,707-byte original with exactly that SHA-256**. The earlier Phase 3 statement that the full PDF was absent described its search at that time; the source document is now available for offline testing. Local provider-response files under `storage/framework/testing/docintel-v10-evaluation` are Northstar normal-route records, as noted in Phase 3. No local record of the original Nasdaq provider response was identified. Production document storage and the production database were **not accessed**, so their retention state is unknown. Recovery of the PDF alone does not create an authentic inference replay.

The repository's `document_ai_runs` stores response status, usage and request metadata; the incremental `document_chunks.result` stores validated records/checkpoints, and `document_evidence` stores merged observations. Neither is automatically an untouched provider response. If production has only chunks and evidence, those can support an **authentic stored-observation replay**, with provenance and original field values preserved, but not an authentic inference replay. An observation reconstructed from the PDF or a new prompt is neither. No result below uses the missing 193 production KPIs as a denominator.

**Prepared read-only recovery procedure, requiring separate approval before execution:** connect to production only with the Railway production service and private network, one `railway ssh` session at a time. In a read-only database transaction, first select `id`, `workspace_id`, `file_hash`, `file_path`, `ai_pipeline`, and revision identifiers from `documents` for the exact document ID and expected hash; stop if either differs. For that document ID and workspace only, export `document_chunks` (`stage`, `status`, `pipeline_key`, `result`, offsets and hashes), `document_evidence` (`kind`, `source_id`, `data`, `sources`), linked `document_ai_runs` metadata, and the exact document's structured extraction and provenance-set metadata if present. Do not select or export other documents, workspace settings, access tokens, environment variables, or credentials. Record table row counts and checksums in a private manifest. Keep raw provider response separate from validated chunk output and merged evidence, and mark it unavailable if no untouched response artifact exists. The complete PDF is already locally recovered, so this procedure does not need to export it. This procedure performs no job dispatch, verification update, or production write. The exact export destination and handling should be approved with production read access.

### Nasdaq: all 84 owner failures classified

The 84 failures are 21 complete rows × four period columns on the visual page-8 statement. The rejection code is the same for each, but the causes differ. The current structured owner rule requires a unique same-page company heading **and** a narrow lexical metric label; it has no section/subsection path for individual rows. The page title itself is `Nasdaq, Inc.` and `Condensed Consolidated Statements of Income`.

| Actual condition, by source section | Cells | Source-backed example | Assessment |
|---|---:|---|---|
| Distinct segment rows under `Revenues:` | 12 | `Capital Access Platforms` 641 and `Market Services` 1,221 in Q3 2026 | Correct company-level rejection. These rows describe business segments; a parent-company owner must not be assigned without a segment scope. |
| Consolidated residual revenue row, missing section inheritance | 4 | `Other Revenues` 2 in Q3 2026, under `Revenues:` | Recoverable in principle from an explicit row-to-section path; the current graph lacks that path. |
| Transaction expense components, missing subsection inheritance | 8 | `Transaction rebates` (623) and `Brokerage, clearance and exchange fees` (289) in Q3 2026, under `Transaction-based expenses:` | Recoverable in principle; do not classify these as independent segment revenues. |
| Operating expense components, missing subsection inheritance | 36 | `Compensation and benefits` 379, `Occupancy` 34, `Depreciation and amortization` 165, under `Operating Expenses:` | Consolidated line items whose owner relationship is visible on the page but not represented in the published row path. |
| Financial line labels excluded by the lexical owner rule | 12 | `Interest income` 10, `Interest expense` (86), `Net gain (loss) on divestitures` (4), all Q3 2026 | The consolidated statement gives a unique reporting context; the row-label regex causes a false rejection. The sign and row identity still require exact cell proof. |
| Dividend-per-share row excluded by the lexical owner rule | 4 | `Cash dividends declared per common share` 0.31 in Q3 2026 | Source label and per-share unit are explicit, but owner proof is missing. It is a different concept from earnings per share. |
| Ambiguous shorthand rows beneath a wrapped section heading | 8 | `Basic` 558.9 and `Diluted` 563.3 in Q3 2026, under `Weighted-average common shares outstanding for earnings per share:` | Correct rejection. The collector currently proposes `$ millions` for these share counts through table-wide notation. Accepting them by broadening owner labels would create a wrong unit/concept; the section and share-count unit must first be published and verified. |

Counts total **12 + 4 + 8 + 36 + 12 + 4 + 8 = 84**. Thus 20 rejections protect a genuine segment or shorthand ambiguity; 64 are incomplete but potentially recoverable structural context. "Potentially recoverable" does not mean eligible under the current graph. No owner relaxation was made: accepting the 64 with a larger word list would also risk accepting the eight `Basic`/`Diluted` share-count cells with the wrong unit. The smallest safe next correction is to publish revision-pinned row-to-subsection edges, with explicit segment, expense, and share-count scope, and then verify those edges. That was not completed or claimed here.

### Northstar: exact failure path after a narrow correction

The independent fictional board pack is seven pages. On its visual page 3, the geometry probe and projector recover one four-row, three-quarter grid (12 cells) beneath `Financial results by quarter (KES million)`. The quarter columns and all 12 values are legible and aligned. The old source-quote binder treated the two `Operating profit` values of `10.0` in Q2 and Q3 as ambiguous because they occur on the same row. For collector-created facts only, the binder now uses the collector's exact projected cell ID, matching source-file hash, evidence identity, row label, cell text and containing quote to choose one of those two cells. Both retain different value anchors and period headers. Model-origin claims receive no such shortcut. The regression checks the two anchor IDs differ and a tampered cell ID cannot bind a value.

| Northstar row | Cells after correction | Result | Source assessment |
|---|---:|---|---|
| `Revenue` 42.0, 46.0, 51.0 | 3 | `governing_owner_unverified` | Unit caption and quarter path are present; the table has no same-page entity heading edge. |
| `Operating costs` 33.0, 36.0, 41.0 | 3 | `governing_owner_unverified` | Same missing owner edge, with an additional lexical owner-label gap. |
| `Operating profit` 9.0, 10.0, 10.0 | 3 | `governing_owner_unverified` | Both `10.0` cells now match their distinct printed columns; no value mismatch remains. |
| `Operating margin` 21.4%, 21.7%, 19.6% | 3 | `unit_source_mismatch` | The cells visibly carry `%`; the collector incorrectly assigns the table's `KES million` to this exception row. The verifier correctly refuses the conflicting unit. |

Totals are **12 extracted, 12 source matched, zero verified/eligible, nine owner rejections and three unit rejections**, zero charts. The page footer names `NORTHSTAR DISTRIBUTION LTD`, and the cover names the same fictional issuer. They are not currently a revision-pinned table-to-reporting-owner path; a footer alone must not be promoted to owner proof. General cross-page cover/header/footer identity and exact table-section relationships would be needed. The percentage exception needs a source-cell `%` unit path distinct from the money caption. Both are general corrections, but neither was added without a cross-document owner and unit conflict model. The page's regional revenue and function-cost tables are single-period compositions outside the current time-series grid; no assertion is made from them.

### Cross-document offline evaluation

Published originals: [Apple FY25 Q2 statements](https://www.apple.com/newsroom/pdfs/fy2025-q2/FY25_Q2_Consolidated_Financial_Statements.pdf), [Walmart Q3 FY26 earnings release](https://corporate.walmart.com/content/dam/corporate/documents/newsroom/2025/11/20/walmart-releases-q3-fy26-earnings/q3-fy26-earnings-release.pdf), and [UPS Q3 2025 earnings release](https://investors.ups.com/_assets/_9262133c36404be24db33ee7b5a2e11e/ups/news/2025-10-28_UPS_Releases_3Q_2025_2150.pdf). The separate Nasdaq rows below show the exact page-8 fixture and the recovered complete original; they are the same issuer and must not be counted as two independent documents. `Probe/projected` separates geometry detection from exact Smalot-text projection. `Matched` means a published structural value path was bound, not that the resulting fact passed semantic verification. `Series/charts/points` reports valid time-series candidates and persisted rows. Rejection counts are by distinct final reason for these source candidates.

| Input (SHA-256 prefix; pages) | Probe / projected tables | Numeric cells / matched | Eligible; distinct rejections | Series / charts / points | Peak RSS; pipeline wall time |
|---|---:|---:|---|---:|---:|
| Nasdaq page 8 (`cece469e3d5b`; 1) | 1 / 1 | 128 / 128 | 44; owner 84 | 11 / 11 / 22 | 89,328 KiB; 1.84 s |
| Nasdaq complete original (`011ca88fabd3`; 17) | 6 / 3 | 172 / 172 | 44; owner 84, scale 16, unit 28 | 11 / 11 / 22 | 106,208 KiB; 7.96 s |
| Northstar fixture (`4507eb76cb2d`; 7) | 1 / 1 | 12 / 12 | 0; owner 9, unit 3 | 0 / 0 / 0 | 81,040 KiB; 1.01 s |
| Apple (`e333dd821d9e`; 3) | 0 / 0 | 0 / 0 | 0; none asserted | 0 / 0 / 0 | 84,844 KiB; 1.68 s |
| Walmart (`86de6bebc085`; 19) | 1 / 1 | 12 / 12 | 0; unit 12 | 0 / 0 / 0 | 97,788 KiB; 1.47 s |
| UPS (`31c1d2c664cd`; 15) | 0 / 0 | 0 / 0 | 0; none asserted | 0 / 0 / 0 | 87,604 KiB; 1.59 s |
| Explicit `USD thousands` synthetic (`cdfd48a8207a`; 1) | 1 / 1 | 6 / 6 | 6; none | 3 / 3 / 6 | 77,156 KiB; 0.69 s |
| Conflicting units synthetic (`19a69c74a8ac`; 1) | 1 / 1 | 6 / 6 | 0; unit 6 | 0 / 0 / 0 | 77,108 KiB; 0.68 s |
| GAAP/non-GAAP synthetic (`6096c48febfd`; 1) | 1 / 1 | 8 / 8 | 8; none | 4 / 4 / 8 | 78,000 KiB; 0.75 s |
| Nonfinancial fixture (`f915252ff0ad`; 5) | 0 / 0 | 0 / 0 | 0; none asserted | 0 / 0 / 0 | 75,808 KiB; 0.61 s |

Complete new-public-file hashes: Apple `e333dd821d9ef827e82d8e0b696493a127c4ee171ea63aca3a80dc1dfea60e89` (3,147,962 bytes); Walmart `86de6bebc0851a20fcc258b097863f46d32f04e92bc6d0c80f7fecff4a5beab3` (463,574 bytes); UPS `31c1d2c664cda9a2b5a6304ff8c79b8440ea5cf0a60731e653b7d56b3c92bec3` (119,172 bytes). These bytes are not committed; URLs and hashes allow independent acquisition and drift detection. The corpus is deliberately small and skewed toward published native-text statements; it is **not** a population accuracy estimate.

On the recovered complete Nasdaq PDF, page 8 still supplies all 44 eligible facts and all 11 charts. Page 9's `Revenue Detail` contributes 16 additional matched cells from four Capital Access Platforms rows, but all fail scale proof because their published table path lacks an applicable unit; section ownership would also need to distinguish the business segment. A third projected `Key Drivers Detail` table contributes 28 cells from mixed listing counts and ETP AUM measures, including `Nasdaq operating company IPOs`, `SPACs`, and `Total average ETP AUM tracking Nasdaq indexes (in billions)`; all fail unit proof because the current path does not bind row-specific count/billions units. Source matching of these 44 extra cells must not be mistaken for accepted financial facts. The full-document run published 328 anchors and 1,545 edges; stage times were extraction/projection 4,141.7 ms, collection 935.9 ms, provenance 629.6 ms, verification 599.4 ms, and persistence 252.3 ms, plus a separate 276.2 ms geometry probe. These are one-run local measurements. The 17-page source document is authentic, but its 172 new source-derived observations are **reconstructed** and do not replay the original provider response or production evidence.

The Apple page visibly has a consolidated income statement with a three-/six-month spanning header, product/service rows, and separate segment/category tables; the probe detects no complete grid, so extraction coverage is zero there. The UPS release page 2 has U.S. Domestic, International, and Supply Chain Solutions segment tables with repeated GAAP/non-GAAP quarter headings; the probe again detects none. Rejecting the layout is safer than joining accounting bases or segments. The Walmart probe finds only page 19's `Certain Balance Sheet Data` table. The printed `Dollars in millions` caption is above a different ROI calculation block, not bound to that balance-sheet table; all 12 candidate units are blank and correctly fail unit proof. A reader may infer a continuing unit, but the current graph cannot prove it. The year columns (2025, 2024, 2023) and values, including `Total assets` 288,655/263,399/259,174, are source matched; there is no verified series. This is a coverage/context gap, not a discovered false acceptance.

### Bounded manual source review and chart quality

The rendered original Nasdaq page-8 image was inspected against eight accepted printed cells: `Total revenues` 2,393/1,979; `Operating income` 732/586; `Net income attributable to Nasdaq` 540/423; and `Basic earnings per share` 0.97/0.74, each in Q3 2026/Q3 2025 order. The printed duration header and `(in millions, except per share amounts)` caption agree with their accepted periods and units. Eight rejected examples were checked visually: segment `Capital Access Platforms` 641 and `Market Services` 1,221; `Transaction rebates` (623); `Compensation and benefits` 379; `Interest income` 10; `Net gain (loss) on divestitures` (4); `Cash dividends declared per common share` 0.31; and shorthand `Basic` shares 558.9. These support the category assessments above. Northstar's two `Operating profit` 10.0 cells and all three `%` cells, plus Walmart's three `Total assets` values, were checked against rendered pages. **No incorrectly accepted fact was found in the eight accepted Nasdaq cells reviewed.** Northstar, Apple, Walmart, UPS and the negative control had no accepted facts, so an incorrect-acceptance count is not applicable for those documents. The synthetic positive controls were exercised by assertions but were not independently manually sampled. This bounded purposive sample does not support a false-positive rate.

The 11 Nasdaq chart titles are: `Basic earnings per share`, `Diluted earnings per share`, `Income before income taxes`, `Income tax provision`, `Net income`, `Net income attributable to Nasdaq`, `Net income from unconsolidated investees`, `Operating income`, `Revenues less transaction-based expenses`, `Total revenues`, and `Total operating expenses`. All 22 persisted points navigate to their exact value anchors. There are no literal duplicate titles or mixed segment/consolidated series among these 11. Q3 2025 and Q3 2026 are the plotted pair for each; nine-month facts remain eligible but are not combined with quarter points. The GAAP/non-GAAP fixture keeps bases separate. No source-backed ISO `USD` or unmarked GAAP identity was inferred.

Chart **prominence** is still weak: `Net income`, `Net income attributable to Nasdaq`, pre-tax income and tax provision are related subtotal/component measures that may crowd the display, while `Net income from unconsolidated investees` is a valid but specialized component. Basic and diluted EPS are different measures, not duplicates. The existing score orders many equal-score rows alphabetically; that is not a meaningful financial priority. A deterministic presentation trial should rank explicitly labeled consolidated outcome/subtotal rows ahead of components while retaining every verified fact and every valid chart as accessible supporting content, and keep period, unit, basis and scope partitions intact. This phase did **not** drop charts or claim a measured UX improvement from an unimplemented ranking rule. A ranking should be tested on at least two real issuers with accepted charts before it becomes a release gate.

### Operational and compatibility findings

- The source collector is PDF-and-flag guarded; it leaves model evidence fields untouched and uses stable file-hash/cell IDs. A second collection retains 128 Nasdaq source facts. Verified chart persistence replaces its own `verified_pdf_table` rows on rerun; the six-observation benchmark remains three charts and six points, without chart-row accumulation. A new equivalent-fact regression verifies that six accepted model facts alongside 128 source candidates still persist 11 unique chart titles and 22 points, one per plotted period. Model and source origins remain separate. Deduplication in every user-facing findings API was **not measured** and remains a release gate.
- The new repeated-value binding corrects Northstar without changing model binding. A failed or missing active PDF extraction now prunes only that document/pipeline's `pdf-cell:` evidence namespace; regression coverage confirms another workspace's 128 facts remain. The persister retracts dependent PDF charts. Changing the extracted-text revision invalidates proofs and retracts charts. Exact same-revision provenance graph mutation is already refused by the publisher.
- On the Nasdaq page, publication created 128 source evidence rows, 229 anchors and 1,190 relationships. Northstar created 12/27/70; Walmart 12/29/74. The geometry probe limits rows to 100 per table and the provenance producer caps anchors/relationships at 20,000/100,000, but a large-table database growth and latency stress test is still missing. A generated candidate per cell is not yet operationally justified for unrestricted uploads.
- Stage timings in milliseconds for the rollback-only run were: Nasdaq extraction+projection 380.6, collection 264.7, provenance 151.6, verification 247.1, persistence 103.2; Northstar 75.6/26.1/19.8/21.9/9.6; Apple 863.4/1.5/8.6/0.7/3.2; Walmart 252.8/30.3/29.7/32.8/16.9; UPS 463.4/2.6/13.7/0.6/4.9. The independent probe took 36.0/43.8/221.9/170.7/134.5 ms respectively and is **not part of those pipeline totals**. Separate Smalot parsing and geometry projection time were not instrumented inside the production extractor. Peak RSS and wall times above are one-run local `/usr/bin/time` measurements, not production percentiles.
- Existing generic incremental tests exercise retry after partial merge, duplicate delivery and checkpoint reuse. The opted-in PDF merge-job test with empty mocked model records charts source cells without a provider call. Source candidates are now excluded from optional paid context-resolution retrieval and from paid synthesis planning; the empty-model integration test asserts zero eligible synthesis inputs after source collection. A forced interruption precisely between PDF collection and provenance publication, and an equivalent model/source finding displayed through the public API, are still untested. No provider token-count change was measured; there is no inference cost-saving claim. No credit or billing code changed.

The independent ownership fix at `fix/docintel-incremental-ownership-review` (`72b5c07`, `7b3a363`) changes `MeasurementIntegrity`, `MetricCollector`, `KpiResource`, and the financial-label classification in `GoverningOwnerProof` even with the PDF flag off. Existing incremental financial evidence without revision-pinned owner proof can lose analytical eligibility, show a KPI limitation, and disappear from chart candidate inputs; previously stored charts are not automatically a proof of current eligibility. Historical impact counts were not measured against production. Release the ownership fix through a separate safety review: first make a read-only, workspace-scoped impact report of current incremental evidence and related chart/API output; then plan bounded re-verification and chart withdrawal/rebuild as a distinct approved rollout. Maintain fail-closed behavior. Do not silently combine its compatibility change with the PDF opt-in rollout.

The final focused local regression run passed **40 tests, 636 assertions, one full-PDF test skipped** before the complete original was recovered. After recovery, the full original PDF safety suite passed **18 tests, 533 assertions, no skips**, including the 17-page test's three verified benchmark charts and six navigable points. The focused run included PDF geometry, source candidate verification and persistence, equivalent-fact chart deduplication, paid-input isolation, flag-off ownership checks, opted-in merge-job integration, and generic interrupted/idempotent merge tests. The earlier PDF safety subset passed **16 tests, 485 assertions, one full-PDF skip** before the equivalent-fact test was added. The default `DOCINTEL_PDF_TABLE_STRUCTURE` remains false. No Power BI access was enabled.

### Release gates still open

1. Recover the original production observations and untouched provider response if available. The exact original PDF is recovered locally; preserve the distinction between that source, stored observations and an inference replay.
2. Publish and verify row-to-section, segment, cross-page report-owner and row-specific unit edges. Prove they recover the 64 Nasdaq consolidated rows and Northstar money/percent rows without accepting segment or shorthand share-count rows. Keep scope, period, unit and accounting basis revision pinned.
3. Produce eligible, manually reviewed charts from **more than one real issuer**; Apple, Walmart and UPS currently contribute zero. Exercise merged headers, segment accounting bases, incomplete/conflicting units and unsupported-layout fallback.
4. Verify equivalent model/source deduplication in user-facing findings, interruption at PDF collection/publication boundaries, large-table storage growth, and resource percentiles. Confirm chart navigation and chart retraction on new file revisions in that expanded corpus.
5. Trial deterministic chart prominence on real accepted charts, preserving all facts and valid charts, and document the observed quality change. Keep the separate ownership safety release impact assessment and confirm no billing/token effect.

Until those gates close, the measured result supports **NO-GO**. The Nasdaq 11-chart capability is real but too narrow to justify even a limited external beta.

## Phase 5 — cross-document table coverage (2026-10-24)

### Decision

**NO-GO for activation.** The default-off source pipeline now makes four manually checked financial comparisons from the independent Apple PDF, in addition to Nasdaq. Northstar's fictional board pack also has four verified charts after a bounded report-owner repair. UPS still yields no projected tables, Apple's balance sheet remains unsupported, and Walmart's balance table lacks a provable unit/date path. Two real issuers with correct charts establish that the architecture can generalize to another native-text layout; the remaining failures do not support a general PDF rollout or external beta. No feature activation, merge, deployment, paid inference, production database access, production write, billing change, or Power BI access occurred.

The three issuer downloads are the exact Phase 4 bytes and SHA-256 values listed above. The complete Nasdaq original retains SHA-256 `011ca88fabd30dd4cb3a27587221811feb69a8f0869a46a2cdef892c975177b2`. The untracked `docs/DOCINTEL_CHART_ELIGIBILITY_REVIEW_2026_10_23.md` predates this work and was left untouched. The separate ownership safety branch was neither checked out nor changed.

### First-loss diagnosis, before changing extraction

The original pages were rendered and checked against `pdftotext -bbox-layout` words. This table separates the first loss from later barriers exposed by fixing it.

| Original table | Poppler words and boxes | First loss in Phase 4 code | Later barrier or remaining limitation |
|---|---|---|---|
| Apple page 1, condensed income statement | Four aligned year/date columns and numeric rows are correctly extracted, including `29,589` at `[333.78,344.72,363.78,355.60]` | `PeriodParser::endedDuration` required the last calendar day of a month. The printed March 29, 2025 and March 30, 2024 fiscal dates returned `null`; `header()` therefore emitted no candidate. | Once periods were recognized, Smalot split each date/year onto separate lines and split `Total net sales (1)` across lines. The old single-line projector rejected the header and that row. The caption also names both millions and a share-count exception. |
| Apple page 2, condensed balance sheet | The March 29, 2025 and September 28, 2024 headers, `Total assets` 331,233/364,980, and boxes are present | The header detector requires a duration scope for year-only rows; a balance sheet has **as-of dates**, so no candidate is formed. | There is no proved as-of path or tested liability/equity section path. This page remains unsupported. |
| Apple page 3, condensed cash flows | Two aligned six-month date columns and numeric rows are correct | The same non-month-end `PeriodParser` condition rejects the header. | Smalot's split header then needs bounded exact projection. Both stages are now repaired for this page. |
| UPS page 2, three segment boxes | Poppler has separate `$676`, `M`, `3Q`, `2025`, `Non-GAAP`, `Adjusted` words at sensible coordinates. The International Segment `Operating profit` row is visible. | `header()` cannot parse `3Q 2025`; even a syntax extension would then see duplicate 2025/2024 columns because GAAP and adjusted columns share periods. The unique-period guard correctly rejects the header. | `numericRow()` requires every period column to be populated and accepts bare numeric tokens, while Revenue has blank adjusted cells and the printed values split dollar/M tokens. The first box's segment name is across the page break. Smalot text order also differs from visual x order. No header/row/basis/segment relationship was asserted. |
| UPS pages 8–14, reconciliation exhibits | Poppler reads the year, GAAP/non-GAAP labels, and values | Single-period side-by-side exhibits or repeated years across several measures fail the unique multi-period header rule; the page 10/14 segment matrices repeat years under separate measures. | A row-to-measure, accounting-basis, segment, and unit path would be required before comparison. |
| UPS page 15, two cost-per-piece exhibits | Poppler reads 2025/2024, `$12.92`/`$11.50`, and the percentage-change column | The mixed year and `% Change` header is not one all-year grid, so `header()` rejects it. | The two duration scopes and GAAP/non-GAAP rows must remain distinct. `pdfplumber` finds isolated numeric rows but does not link them to the correct duration headers. |

For Apple page 1 and 3, the first loss was period parsing, followed by exact text projection. Later provenance and structural validation passed once those two stages were corrected. For UPS, the first loss is header recognition; it is inaccurate to blame Smalot projection or the verifier for zero tables because no candidate reaches them.

### Alternative extractor benchmark

An isolated `pdfplumber==0.11.7` prototype uses its `lines` and `text` table strategies on the same original pages. It was installed only under `/tmp/docintel-pdfplumber`; no Python service or production dependency was added. Its [official license](https://github.com/jsvine/pdfplumber/blob/stable/LICENSE.txt) is MIT; the [official table documentation](https://github.com/jsvine/pdfplumber/blob/stable/README.md#extracting-tables) describes the line and inferred-text strategies and cell coordinates. The local installation additionally pulled `pdfminer.six`, Pillow, pypdfium2, cryptography, cffi, charset-normalizer, and pycparser. The current container has Poppler but no Python installation path for this feature, so integration would add packaging and subprocess maintenance.

| Same original page | Poppler probe/projector: useful rows × value columns | `pdfplumber` raw line/text output | Source-relationship assessment | Poppler seconds / peak RSS KiB | `pdfplumber` seconds / peak RSS KiB |
|---|---|---|---|---:|---:|
| Nasdaq page 8 | 32 × 4, three-level period path | 0 line tables; text 57 × 17 | Over-split text grid, no correct multirow header path | 0.440 / 71,904 | 0.597 / 45,492 |
| Apple page 1 | 19 × 4 main statement; footnote exhibits excluded | lines 44 × 8 including four spacer columns and footnote exhibits; text 85 × 16 | Headers fall outside the ruled table; one printed `23,867` becomes `2 3,867`. Some individual numeric cells have usable coordinates, but no exact four-column period path. | 0.736 / 79,516 | 1.309 / 53,648 |
| Apple page 2 | 0 | lines 40 × 4; text 78 × 13 | `Total assets` row/values appear, but the as-of header is outside the extracted table and spacer columns remain. Numeric recovery improves; full source-context accuracy does not. | 0.412 / 77,524 | 0.404 / 47,332 |
| Apple page 3 | 28 × 2 | lines 38 × 4; text 77 × 13 | `Net income` values appear in columns 2 and 4, but the six-month date path is outside the table. | 0.514 / 77,452 | 0.400 / 47,608 |
| UPS page 2 | 0 | 0 line tables; text 55 × 14 | Text grid mixes three segment boxes and splits labels/values; no safe GAAP/adjusted path. | 0.157 / 68,592 | 0.563 / 44,604 |
| UPS page 15 | 0 | eight mostly one-row line tables; text 41 × 5 | Dollar and percentage cells are separated, with no verified three-/nine-month header ownership. | 0.090 / 68,892 | 0.221 / 43,288 |
| Walmart page 19 | 4 × 3 balance data | one 40 × 6 line table; text 69 × 16 | The line table combines ROA, ROI and balance data; balance values occupy one text cell per row rather than three dated cells. | 0.127 / 73,180 | 0.483 / 42,952 |
| Northstar page 3 | 4 × 3 | three line tables, main table 5 × 1; text 49 × 16 | The main line cell contains whole rows, with no quarter column relationships. | 0.104 / 68,352 | 0.291 / 40,912 |

Both methods expose word coordinates. `pdfplumber` exposes cell boxes too; its Apple page-1 `Operating income` box contains the correct word box, but its extra spacer columns and missing period header prevent direct provenance publication. Poppler's projected cells retain exact Smalot offsets and Poppler PDF-point boxes. `pdfplumber` did not recover a single additional **complete** row/period/unit/scope relationship in these settings, despite finding useful Apple and UPS row text. No integration was made. The prototype is `bin/docintel-pdfplumber-benchmark.py`; `bin/docintel-poppler-benchmark.php` reproduces the one-page comparison. Each timing is one fresh process on the stated page; the PHP command includes Laravel bootstrap, Smalot and two Poppler passes, while the Python command runs both table strategies. They are indicative resource observations, not normalized throughput or production percentiles.

### Independent source reference and measured coverage

`docs/audits/docintel-pdf-phase5-reference-values.csv` contains 22 visually transcribed printed values, with row label, period column and duration, unit, reporting scope, stated accounting basis, original page, and PDF-point cell box. The entries were checked against rendered originals and a separate Poppler word-box read, without using production extractor output as an extraction rule. This is one reviewer plus an independent representation check, **not** a second human review. The score script `bin/docintel-pdf-reference-score.py` reads the reference and rollback-only audit JSON; it does not feed production extraction.

The scorer checks exact row/value/box, the duration-bearing period, normalized printed unit, issuer identity, and absence of an unsupported GAAP/non-GAAP claim for selected positives. Consolidated or segment scope is also checked visually against the statement and section title; that richer scope is not reduced to an issuer-name string in the score script.

| Corpus | Exact numeric + row + box | Correct period path | Selected source-supported positives verified | Negative cases wrongly accepted |
|---|---:|---:|---:|---:|
| Nasdaq (4 references) | 4 | 4 | 2/2 | 0/2 |
| Apple (6) | 5 | 5 | 4/4 | 0/2 |
| UPS (5) | 0 | 0 | No eligible positive selected | 0/5; all absent |
| Walmart (3) | 3 | 0: bare years omit the October 31 as-of date | No eligible positive selected | 0/3; all rejected |
| Northstar (4, fictional) | 4 | 4 | 4/4 | No negative value selected |
| **Total (22)** | **16/22** | **13/22** | **10/10 selected** | **0/12 selected** |

The negative set contains six reconstructed/rejected cells and six absent cells. This purposive sample tests particular relationships and adversarial cases; its 10/10 selected positives and 0/12 selected false acceptances are **not** estimates of corpus recall or false-acceptance rate. Walmart's three values are numeric matches but do not satisfy full period-context coverage. UPS and Apple's page-2 as-of value show the missing-layout coverage directly.

### Implemented bounded changes and remaining context gaps

- A non-month-end `Three/Six/Nine/Twelve Months Ended` header retains its exact printed date and duration, instead of being called a calendar quarter. The Nasdaq month-end Q3/9M labels are unchanged. Multi-line Smalot projection requires an ordered, bounded exact sequence and rejects a missing value or intervening row text. Apple's main statement stops at the standalone numbered footnote exhibit. The source text itself is unchanged.
- A caption with a primary scale and an explicit share-count exception supplies the primary scale only to rows outside that exception. Apple `Basic`/`Diluted` share counts remain ineligible. A cell printed with `%` supplies its own percent unit; Northstar `Operating margin` no longer inherits KES million. Walmart's unrelated preceding `Dollars in millions` caption still does not bind to `Certain Balance Sheet Data`.
- Northstar's table gains a report-owner path only when a cover name is followed by a report title, the same unique name appears in the table-page footer, the Poppler footer box is below the financial grid, and the table caption says financial results. Cover and footer anchors, table, row and value are included in the same revision-pinned provenance set. A footer alone or a conflicting footer name fails the owner proof. This establishes a source-supported owner for the fictional report, without treating arbitrary page footers as company proof.
- The Nasdaq subsection investigation confirms that geometry sees the section text but the projector does not publish row-to-subsection nodes or edges. The same 84 cells remain owner-rejected: 64 potentially recoverable consolidated line items, 12 segment rows, and eight `Basic`/`Diluted` share counts. A larger owner-label regex cannot safely replace those edges. Revision-pinned section ownership with explicit segment and share-count scope remains a separate implementation gate. No Nasdaq-specific owner rule was added.

### Final rollback-only source flow and chart review

The full structured extraction → separate `pdf-cell:` candidates → provenance publication → exact value/period/unit/owner verification → chart builder → idempotent persistence flow ran in disposable PostgreSQL 18 transactions. Each transaction rolled back. The table counts are complete candidates, not claims made by a model. Incorrect table detections in the inspected statement pages were zero; the other two projected Nasdaq detail tables were not visually re-adjudicated in Phase 5. Apple page 2 and UPS are missed tables, and Walmart's detected table has an incomplete as-of/unit context.

| Input | Correct / incorrect projected tables | Numeric / source-matched cells | Verified / eligible | Rejections | Valid series | Charts / navigable points |
|---|---:|---:|---:|---|---:|---:|
| Nasdaq page-8 fixture | 1 / 0 | 128 / 128 | 44 / 44 | 84 owner | 11 | 11 / 22 |
| Nasdaq complete 17-page original | 3 / 0 confirmed; two detail tables not re-reviewed | 172 / 172 | 44 / 44 | 84 owner, 16 scale, 28 unit | 11 | 11 / 22 |
| Apple three-page original | 2 / 0; balance sheet missed | 132 / 132 | 18 / 18 | 94 owner, 20 unit | 4 | 4 / 8 |
| UPS 15-page original | 0 / 0; financial grids missed | 0 / 0 | 0 / 0 | none asserted | 0 | 0 / 0 |
| Walmart 19-page original | 1 / 0; as-of path incomplete | 12 / 12 | 0 / 0 | 12 unit | 0 | 0 / 0 |
| Northstar seven-page fictional fixture | 1 / 0 | 12 / 12 | 12 / 12 | none | 4 | 4 / 12 |

All eight Apple persisted points retain exact value-anchor offsets and page navigation. The original page images were manually checked for the plotted pairs: `Net income` 57,552 → 61,110 (six months, page 3), `Operating income` 68,273 → 72,421 (six months, page 1), `Total operating expenses` 28,853 → 30,721 (six months, page 1), and `Income before provision for income taxes` 28,058 → 29,310 (three months, page 1). Each comparison keeps the same row, duration, `$` marker, millions caption, Apple reporting entity and unmarked GAAP/non-GAAP basis in both periods. The page-3 net-income pair agrees with the page-1 six-month pair. The chart builder did not combine three- and six-month points. Northstar's twelve points were checked against the page-3 grid, including different anchors for the two printed `10.0` profit cells and `%` for all margin cells. No unsupported ISO `USD` or GAAP status was added.

The final focused local regression gate passed **114 tests and 1,094 assertions** across period parsing, geometry, projector, source graph, chart contracts and the original 17-page Nasdaq PDF. The exact-hash Apple and Walmart optional tests passed locally; the main Nasdaq fixture still has 44 eligible cells and 11 charts, and the complete original also has 44 and 11. Existing model-origin evidence remains untouched, source-origin evidence stays separate, chart persistence remains idempotent, and the PDF flag remains false by default.

The working `pdfplumber` prototype is retained for a future larger evaluation. A future UPS or as-of balance-sheet implementation should first establish explicit period/basis/segment header ownership and exact Smalot projection on a reference corpus. It should not be shipped by treating nearby text or a plausible arithmetic pair as a source edge. The original production Nasdaq provider response remains unavailable for an authentic inference replay, and the Phase 4 release gates on model/source display deduplication, interruption behavior, resource percentiles, and the separate ownership safety rollout remain open. **Recommendation: NO-GO for activation or beta; continue default-off development and review the isolated ownership safety branch separately.**
