# DocIntel PDF table structure evaluation — 2026-10-23

## Decision

**Keep `DOCINTEL_PDF_TABLE_STRUCTURE` off.** A bounded Poppler subprocess plus a strict text projector reconstructs the Nasdaq income statement and publishes real row and column edges in an isolated test. It does **not** yet establish all field roles needed for a financial chart. The incremental verifier rejected all six benchmark observations, and no chart was created. Enabling the path now would make the graph richer without making the example chart eligible. No production inference, database write, deployment, paid API or billing change was made.

The code is a narrow, default-off PDF path. Smalot remains the prose extractor and fallback. This branch also closes the pre-existing incremental owner-check bypass in evidence assessment, metric collection and KPI API output.

## Inputs and reproducibility

- Original public document: [Nasdaq Q3 2026 earnings release](https://ir.nasdaq.com/static-files/83ca6992-6374-4358-838f-402a1d65daf4), supplied locally by the user after the investor-relations download returned HTTP 403. SHA-256: `011ca88fabd30dd4cb3a27587221811feb69a8f0869a46a2cdef892c975177b2`; 17 pages, 713,707 bytes. The committed one-page fixture is page 8 of that exact PDF, produced with `pdfseparate`; SHA-256 `cece469e3d5b2bc932a503c11253ea893ac8dad2640d21f83c4d1e362fb36c66`.
- Independent financial fixture: `tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf`, SHA-256 `4507eb76cb2dfce4be55c23e6572a70e473a1bab520071734065f64755384f2f`.
- Adversarial fixture: `tests/Fixtures/financial_layout_adversarial.ps` and its PDF. It includes a valid quarter grid, a nine-month heading over misleading Q3 labels, repeated conflicting Q3 headers and a parenthesized negative.
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

## Why no Nasdaq chart was created

The actual incremental path was exercised locally: structured extraction → `StructuredExtractionStore` → `TextRoleProvenanceProducer`/`StructuredProvenanceProducer` → `SourceProvenanceReader` → `IncrementalMetricFieldVerifier` → `MetricCollector` → `ChartCandidateBuilder`. The source graph passed structural validation and the six values plus exact row labels bound. Canonical periods and units did not bind to any single verbatim anchor, so the field verifier rejected all six. The owner gate also correctly requires a governing-entity proof; a page heading alone is not that proof. No observation reached chart eligibility, and no chart row was inserted.

The remaining implementation work is specific: add source-backed bindings for a **path** of original header anchors, validate its canonical period with `PeriodParser` at publication and verification time, bind scale/currency separately to their actual source markers, and build revision-pinned governing-owner proof for the table without overriding a segment caption. Metric aliases such as “net revenue” also need a checked mapping to the source row “Revenues less transaction-based expenses.” GAAP and non-GAAP rows must remain different identities. Until those checks are in place, the flag stays off.

## Verification and limitations

- Focused unit tests: 79 passed, 369 assertions in the final unit run.
- Local feature tests: the final combined run passed 57 tests and 414 assertions across chart, materiality, structured DOCX/XLSX provenance, Nasdaq incremental safety, provenance publication and owner proof. An earlier chart/materiality run passed 15 of 16 because the deliberately limited local schema lacked a status-check-only migration; applying that one local migration resolved the fixture failure. No access-related Power BI migration was run.
- Adversarial coverage currently checks a Q3 period reassignment, an x-incompatible spanning header, nine-month/Q3 separation, repeated conflicting headers, parenthesized negatives, and no financial table in a nonfinancial PDF. **Not yet independently covered:** thousands versus millions across a bound unit path, GAAP/non-GAAP identity conflicts in a verified series, wrong business segment ownership, adjacent unrelated columns beyond the strict x-alignment fixture, scanned pages, rotated pages, merged row labels, and continued tables without repeated headers. The flag must remain off until these are tested through the full verifier.
- Geometry candidates on pages 11–13 of the full Nasdaq report did not all project onto exact smalot lines. Their omission is deliberate: no graph edge is emitted for an unmatched row. The page-8 statement is complete for the benchmark pairs; other rows with dashes or wrapped labels may be omitted.
- Poppler word coordinates are PDF points and are converted to the bottom-left geometry convention of `SourceProvenance`. No OCR table geometry is yet emitted. `PdfHybridAssembler` preserves native table nodes and shifts their offsets when another page is filled by OCR.

No production flag, migration, inference route, credit/billing component or deployment was changed. The work is committed only to the dedicated feature branch.
