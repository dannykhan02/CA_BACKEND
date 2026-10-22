# DocIntel evidence-first implementation

22 October 2026. Branch: `feat/docintel-evidence-first`, based on completed V10 work at `cbb8586`. This report describes code and local tests, not a deployment. The untracked architecture challenge report and restricted provider diagnostics remain outside Git. No paid inference, production database operation, prompt activation, migration, dependency installation, push, or merge occurred.

## Findings checked against code and execution

- The Northstar PDF SHA-256 is `4507eb76cb2dfce4be55c23e6572a70e473a1bab520071734065f64755384f2f`. Its extracted text SHA-256 is `a4722cfdb3795de38553953d1c12521053a5368232bf3a0cbcd4a952712fa8da`; the normal span builder persisted 126 spans. The frozen provider body matches recorded SHA-256 `622cca54e2ea149e36ada8fb3d177d93ea00613cc853f204d42d83bf023de6aa`.
- The unchanged frozen V10 response replayed through the real Anthropic response parser, V10 validator, identity resolver, insights job, grounding, evidence persistence, integrity, deterministic analytics, and composite API resource. The original 13 subject, 3 period, and 4 concept first rejections reproduced exactly with the flag off.
- Native DOCX extraction already records table, row, cell, `grid_column`, `grid_span`, vertical merge, explicit header, and exact text offsets. Native XLSX extraction records sheet, coordinate, merge, visibility, formula, table range, and exact offsets. PDF structured extraction currently records pages only; it does not establish table cells.
- The PDF space-aligned table fix was already in place: `PdfTabularCellVerifier` splits tabs, pipes, and repeated spaces and rejects a typed table row that it cannot align. Its database-backed wrong-period regression remains green.
- `DatabaseSeeder` invokes `AiPromptSeeder`, which activates document insights V3. V9 and V10 seeders create inactive successors. Local replay activates V10 only inside a rolled-back evaluation transaction. Repository seeding does not establish which version is active in production.
- The V9 audit's prediction that a one-observation-per-cell prompt would be nearly sufficient was falsified by the V10 live result. Claude's offline improvement was reproducible in direction, but its counts were not treated as a validation target.

## Changes and commits

| Stage | Change | Commit |
|---|---|---|
| 1 | Native DOCX cell proof through `record()`, refusal of wrong-period DOCX cells, and rejection of a parent page banner that conflicts with a subsidiary table caption | `4343031` |
| 1 follow-up | Multirow/merged DOCX period and basis header resolution, with the basis header's own source span retained | `e16c446` |
| Provenance repair | Shared period/unit header with a row basis column; partial-quarter matching; currency and scale conflict reasons after column selection; isolated V9 evaluation opt-in | `e16c446` |
| 2–4 | Default-off `EvidenceResolver`, bounded `RowExpander`, header-governed concepts, exact compact monetary prose | `e16c446` |
| 5 | Read-only common native DOCX/XLSX cell projection; PDF geometry withheld | `e16c446` |

The two original false-acceptance paths were observed before the safety fix in database-backed tests: a DOCX Q1 claim for its Q3 cell returned success and wrote metric evidence, and a parent banner claim for a subsidiary-captioned PDF table did the same. Two further ownership variants, an explicit subsidiary caption without a legal suffix and a page banner naming two companies, were also reproduced and fixed. All four now reject through `record()` and leave no metric evidence. Correct DOCX cells and a subsidiary-specific caption remain eligible. A merged Q3 DOCX header with separate Actual and Target child headers now accepts the target only in its own grid column. The existing PDF false-acceptance regression remains green.

### Normal route provenance failures

The four observed `NormalRouteProvenanceTest` failures were verifier regressions, not bad expectations. The first fixture put `USD 51.0` under a shared `KES million` header. The selected cell's explicit currency contradicts the claim, so `quantity_mismatch` is the useful refusal after the correct column is established; a generic `value_column_mismatch` concealed that conflict. The second fixture cited one full `Q3 2026` header for both period and unit while the model gave `Q3`. The old matcher tried to parse `Q3` as a full period and rejected the sole matching column. It now permits that exact quarter match and resolves the year from the proven header; a header containing both `Q3 2025` and `Q3 2026` still rejects as ambiguous. The published-style and actual/target fixtures used a three-cell layout `Metric | Q3 2026 | unit` over `Revenue | 51.0 | Actual`. The verifier mistook the last row cell for a second measurement column. It now recognizes this narrow layout only when the last header is the claimed unit and the last row cell is an explicit basis; it still requires the selected value column and matching actual/target basis. Added database-backed cases reject conflicting period columns, mismatched quantity/scale, multiple value columns, and a target claim over an actual row. Source-document and revision checks remain active.

`EvidenceResolver` accepts a source anchor only in the current extraction revision. It asks the existing table verifier to prove the anchored cell before repairing an insufficient citation. It can substitute a uniquely matching direct table caption or page owner for a model citation that merely repeats the value row. It does not replace a citation that explicitly points to a contradictory entity. A printed `Q3 target` header can be separated into quarter and basis only when the claimed basis agrees; a year is added only when the first page span explicitly states that same quarter and year. The original and resolved span IDs are retained in evidence diagnostics. The grounder still performs every ordinary role and cell check.

`RowExpander` works on an already proven table row with a cited header containing unique, fully printed periods. It emits sibling values from the same row, keeps the printed cell text, and submits each result to ordinary grounding and integrity. It abstains on undated quarters, duplicate period columns, category columns, visible blank-line table boundaries, unsupported anchors, and a period/basis identity already claimed by the model. Expanded KPI identity metadata says `server_row_expansion`; the model observation count is reported separately.

## Full unchanged V10 replay

| Metric | Flag off | Flag on |
|---|---:|---:|
| Model observations | 20 | 20 |
| Server expanded observations | 0 | 4 |
| Source-linked metric observations | 0 | 22 |
| Exact cell proven observations | 0 | 22 |
| Analytically eligible observations | 0 | 14 |
| Complete Q1–Q3 financial series | 0/4 | 4/4 |
| Trusted deterministic charts | 0 | 4 |
| Deterministic comparisons | 0 | 8 |
| API evidence mappings for referenced items | 0 | 20–22 across repeated replays |

The original 20 values were manually judged numerically source-correct in the prior live review; this replay did not repeat manual factual adjudication. The four recovered facts are Q1 revenue 42.0, operating costs 33.0, operating profit 9.0, and operating margin 21.4%, each with its own cell offset. The existing builders produced all four Q1–Q3 series without model chart JSON or a frontend change. API evidence maps only referenced results by design, so fewer mappings than 22 stored metric evidence rows are expected in some runs.

Flag-on first-level outcomes among 24 persisted KPIs: 22 passed grounding and 2 bare-Q2 claims remained `period_ambiguous`. Of the 22 source-linked observations, 4 unitless counts remained `unit_unverified` and 4 periodless proposed amounts remained `period_unavailable`; 14 were eligible for analytics. A correct numerical cell with one of those limitations is source-linked, not analytically verified. No year was inferred for the Q2 claims from an unrelated Q3 page banner. The composite API includes only evidence referenced by its composed analysis; its selection varied between 20 and 22 mappings in repeated runs. Every returned mapping had a quote and source spans. The count variation is a reporting limitation and should be made deterministic before using exact API mapping totals as a product metric.

## Regression and compatibility results

- The affected `NormalRouteProvenanceTest`, `NormalKpiTableSafetyTest`, and `EvidenceFirstSafetyTest` suites pass against disposable local PostgreSQL. New cases cover a shared period/unit header, actual versus target in a row basis column, ambiguous periods, multiple value columns, conflicting currency and scale, wrong DOCX period, merged DOCX Actual/Target columns, conflicting parent/subsidiary ownership, deliberate contradictory subject citation, stale text revision, unsupported row expansion, and compact prose currency, scale, sign, and repeated-value forms.
- The guarded V9 recorded evaluation passes with `DOCINTEL_V9_EVAL_RUN=1`: 5 tests, 224 assertions, no live inference. Ordinary CI skips its five tests before opening its dedicated database; the existing host, port, database name, cluster path, and empty-database checks still apply when opted in. The V10 evaluation suite has the same default skip behavior; the frozen replay was run explicitly against an empty disposable local database and retained every cluster/source guard.
- The final complete safe backend suite passed: 1,822 tests, 1,808 passed, 14 skipped, 13,199 assertions. Its only notices were five existing PHP 8.5 `imagedestroy()` deprecations in OCR code/tests. The final affected deterministic slice passed 29 tests and 542 assertions; the explicit frozen V10 replay passed 2 tests and 100 assertions; the explicit V9 recorded evaluation passed 5 tests and 224 assertions. Existing frontend consumers of `analysis.visualAnalysis`, `sourceVerification`, `analyticalEligible`, and the referenced evidence map were inspected. No public trust label, chart builder, comparison builder, frontend component, or API contract was changed. The new capability flag is `DOCINTEL_SERVER_EVIDENCE_RESOLUTION=false` by default. No additional model call was introduced.

The four reproduced false-acceptance exploits now have 0/4 accepted claims through persistence. The seven existing Northstar wrong-role/wrong-value adversarial claims remain rejected; these are specific test denominators, not a universal false-acceptance estimate. The local broader corpus does not support a defensible general false-acceptance rate. Four source-correct count claims among the 20 model observations remain analytical false-negative candidates, but their quantity kind needs independent proof; treating them as verified merely from the model's `count` label would risk false acceptance. The four periodless proposal values are correctly withheld from time series.

## Shared cell model and PDF boundary

`NativeCellProjector` exposes one read-only cell shape for the active DOCX/XLSX extraction: document/source revision, parser version, page/sheet, table identity, row/column, row/column span, exact text offsets, explicit header flags, optional caption/bounding box, and extraction confidence. Missing relationships remain null. Its tests compare projected text ranges with the stored exact source and reject a changed text revision. This is an adapter and proof of concept; the XLSX and DOCX production verifiers still use their existing native structures. It does not change storage or introduce a migration.

Poppler `pdftotext -bbox-layout` is installed, but geometry words are not automatically compatible with Smalot's stored `extracted_text`. On Northstar, simple page-local sequential exact matching found 1,085 of 1,325 geometry words; 240 were unmapped, including 85 on page 3 and 95 on page 4 where the important tables sit. 656 geometry words repeat on their page, making first-match offsets ambiguous. This is a bounded failure measurement, not a completed PDF table parser. There is no new PDF geometry path or runtime dependency. Automatic table region detection, wrapped cells, continuation, adjacent tables, and exact offset mapping require a separate evaluation. If an existing-dependency approach cannot pass those cases, evaluate Docling separately before adding a runtime.

## Remaining work, in correctness order

1. Replace the current bounded banner/caption ownership rules with a structural ownership graph and a broader adversarial corpus. The feature remains off by default while ambiguous entity reports are under-tested.
2. Prove count quantity kinds from exact row and bounded glossary/definition relationships, including shipments and complaints, before promoting the four unitless Northstar observations. Keep count populations separate in chart grouping.
3. Establish explicit caption, wrapped header, section unit, and glossary edges in the common cell model. Periodless proposals should stay off chronological charts even when their values are grounded.
4. Validate on 12–15 authorized documents covering contracts, prose, DOCX, XLSX, scanned PDFs, irregular financial tables, multiple entities, merged headers, and multi-column layouts. The current product outcome is demonstrated on one fictional PDF plus synthetic adversarial controls, not generalized to customer documents.
5. Measure worker latency and newly rejected documents on that corpus. No paid simplified-prompt experiment should run before backend evidence recovery generalizes.

One local replay measured 640 ms for the flag-off parser-to-API path and 1,804 ms with the resolver and four extra persisted cells. This is a single warm local measurement, not a production latency estimate. The flag-on path made the same single mocked provider request and no additional model calls.
