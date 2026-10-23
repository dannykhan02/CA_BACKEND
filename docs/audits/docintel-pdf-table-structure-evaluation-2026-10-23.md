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
