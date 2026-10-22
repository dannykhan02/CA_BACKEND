# DocIntel evidence-first generalization gate

22 October 2026. Evaluation branch: `feat/docintel-evidence-first`. This is an **offline evaluation of one fictional PDF and synthetic controls**, not a real-document generalization result or an activation recommendation. No production verifier, prompt, API contract, schema, dependency, or feature default was changed. `DOCINTEL_SERVER_EVIDENCE_RESOLUTION` remains false by default.

## What was inspected and what runs

The normal route takes exact extractor text through `SourceSpanBuilder`/`EvidenceSpanSet`, V10 response validation, `GenerateInsightsJob`, KPI identity, optional `RowExpander`, `NormalKpiGrounder`, optional `EvidenceResolver`, metric evidence persistence, `MeasurementIntegrity`, `MetricCollector`, deterministic chart/comparison builders, and `DocumentIntelligenceResource`. The frozen replay calls the real Anthropic response parser with a fake HTTP response and then runs that backend path. It makes one mocked provider request per replay and zero paid calls. The on/off tests each run in a rolled-back transaction on a named, disposable local PostgreSQL cluster; the existing test refuses the wrong host, port, database, cluster directory, or nonempty evaluation database.

DOCX extraction already preserves native table cells, grid columns/spans and exact offsets; XLSX preserves sheets, coordinates, merges, formulas and table ranges. `StructuredExtractionStore::active()` binds those nodes to the document hash and current text hash. The PDF path has page spans and a strict verifier for aligned text rows, but no native PDF cell grid. `NormalKpiGrounder` checks the pipeline text hash, resolves exact span IDs, refuses failed table-cell checks without prose fallback, and records original/resolved citations and source revision. `MeasurementIntegrity` requires current revision, numeric parsing, field roles and unit proof before analytical eligibility. The composer only sends eligible observations to the unchanged deterministic builders; the composite API maps referenced source IDs and checks each stored quote against current text. Model-authored V9 charts retain `model_unverified` provenance; the V10 replay uses deterministic charts.

The guarded entry point today is `NorthstarV10LiveEvaluationTest` filtered to its two frozen-response methods. The new `tools/evidence_first_gate.py` verifies registered source, response, gold, extraction and span-revision hashes, alternates flag order, runs the backend tests, scores against registered independent truth, runs synthetic adversarial cases, and writes a restricted JSON result under `storage/framework/testing/docintel-evidence-first-gate/`. It does not turn a missing fixture, response or truth file into a passing result. A new document needs an authorized local source, human gold, a frozen response, and a guarded backend replay adapter. The current executable adapter is Northstar-specific; there is no DOCX/XLSX or real-document end-to-end replay adapter yet.

Run, after starting the disposable cluster at `storage/framework/testing/docintel-v9-pg` on `127.0.0.1:55449`:

```bash
python3 -m unittest discover -s tests/Evaluation -v
python3 tools/evidence_first_gate.py --repeat 5
```

The gate fixes the test database name to `docintel_v9_eval`, disables live evaluation, and uses the existing cluster/empty-database guards. Its machine-readable artifact contains aggregates and hashes, not the provider body or credentials. Restricted diagnostics stay ignored by Git. `tests/Fixtures/intelligence-v2/evidence-first-corpus.json` is the inventory; `evidence-first-truth-spec.json` declares source-gold origin, extraction revision, 29 transcribed facts, the 12 financial series operands, and which eligibility decisions are reviewed. The existing `northstar-source-gold.json` records exact source values, pages, tables, rows, columns, periods, units, basis and governing subjects. The synthetic negative/positive decisions are in `evidence-first-adversarial-claims.json`, separate from source gold.

## Corpus inventory and coverage

Tracked physical documents comprise Northstar's **fictional** seven-page financial PDF, `test_5page.pdf` (a short synthetic prose/rasterizer fixture), and `embedded-emf.docx` (an image extraction fixture). Only Northstar has both a frozen V10 response and independently transcribed source gold. The latter two have no model-response replay or claim-level human gold. Synthetic DOCX/XLSX documents are generated inside tests, not held out real reports. `entity-ownership-replay.json` contains prior system/brief records but no authorized source documents for independent adjudication, so it is not counted as real ground truth. Files under private customer storage with unclear authorization were not opened. **Authorized real evaluation documents: 0.**

| Requested structure | Present evaluation evidence |
|---|---|
| Standard financial PDF | Fictional Northstar frozen replay |
| Irregular financial PDF | Synthetic verifier tests only |
| Multi-page table | Missing |
| PDF merged or wrapped header | Missing independent replay |
| Parent and subsidiary | Synthetic persistence regressions |
| Several organizations | Synthetic persistence regressions |
| Native DOCX table | Generated synthetic DB test |
| DOCX merged period/Actual/Target header | Generated synthetic DB test |
| XLSX multiple worksheets | Existing synthetic extraction tests; no frozen replay |
| XLSX merges and formulas | Existing synthetic extraction tests; no frozen replay |
| Contract obligations and deadlines | Missing |
| Prose-heavy business report | Short synthetic fixture; no frozen replay |
| Scanned or hybrid PDF | Missing |
| Identified population counts | Fictional Northstar and synthetic cases |
| Conflicting figures, missing periods or ownership | Synthetic regressions |

The matrix covers 15 requested categories as an inventory, **not** 15 evaluated documents. A defensible real-document corpus still needs roughly 12–15 authorized reports/contracts across these structures, frozen responses or other offline candidate fixtures, and human review that records expected positives and negatives before the gate can estimate generalization.

## Independently grounded result on the available fictional source

Five alternating, separate-process off/on replay pairs used the same unchanged frozen response and source hashes. Denominators refer to persisted observations unless otherwise shown. The strict exact-gold matcher requires label, unit, subject, period, basis, value, page and row. A second conservative numerical matcher requires a **unique** human-transcribed page, row, printed magnitude and quarter; it never upgrades a candidate's verification status. Four observations outside that transcribed row set remain unknown in both paths. Earlier manual review called all 20 emitted values numerically correct, but this gate does not substitute that aggregate statement for per-claim independent matches.

| Measure | Flag off | Flag on |
|---|---:|---:|
| Model observations | 20 | 20 |
| Server-expanded observations | 0 | 4 |
| Unique source-gold row/value/quarter matches | 16/20 | 20/24 |
| Strict exact-gold semantic matches | 8/20 | 12/24 |
| Source-linked metric observations | 0/20 | 22/24 |
| Exact cell provenance persisted | 0/20 | 22/24 |
| Analytically eligible | 0/20 | 14/24 |
| Gold facts found by strict matcher | 8/29 | 12/29 |
| Reviewed financial operands eligible | 0/12 | 12/12 |
| Complete financial time series | 0/4 | 4/4 |
| Trusted charts emitted | 0 | 4 |
| Deterministic comparisons emitted | 0 | 8 |
| Valid referenced API evidence mappings | 0/0 | 20/20 |

All 20 model observations were retained; the four new facts are explicitly attributed to server row expansion. The twelve independently specified financial operands belong to four Q1–Q3 series. The gate checked each persisted evidence source quote, exact cell substring, current text revision, and API mapping range during backend replay. The output count of eight comparisons is **not** an independent human review of every comparison's interpretation. Likewise, an API mapping count is a count of *referenced* evidence, not all 22 metric evidence rows. This five-pair run had the same API mapping selection each time (20); an earlier implementation replay observed 20–22, so full API-selection determinism remains unproven.

The flag-off first refusals reproduced 13 `subject_source_mismatch`, 3 `period_ambiguous`, and 4 `concept_row_mismatch`. With the flag on, two bare-Q2 claims remained `period_ambiguous`; four source-linked counts remained `unit_unverified`; four source-linked, periodless proposals remained `period_unavailable`. Among the 20 unique numerical source matches with the flag on, the gold comparison found **zero explicit basis conflicts, zero full-period conflicts and zero explicit unit conflicts**; two periods were incomplete and four units were missing. Four matched count claims named the correct independently transcribed populations (0/4 population-label conflicts), although their unit/quantity proof was insufficient for analytics. One model scope named the service-recovery budget while the source gold named the reporting company. That is a population-versus-owner distinction needing human review, not a confirmed false attribution; that observation was not analytically eligible. The four unmatched observations are unknown, not errors or successes.

For the strict, fully matched claims, flag off rejected 8/8 for grounding and flag on rejected 0/12. These are a narrow, reviewed false-rejection denominator. The two Q2 claims and four unitless counts are source-correct numerical candidates with incomplete analytical proof; treating them as eligible would violate the current trust gate. No negative claim in the frozen provider response has an independent label, so its false-acceptance denominator is **0**, not an invented zero-error rate.

## Adversarial trust-boundary result

The new synthetic claim fixture exercises 11 claims under both feature states: 9 invalid claims and 2 valid controls. All **18/18 invalid claim decisions were rejected**, with **0/18 false acceptances at the grounding boundary**; all **4/4 valid control decisions grounded**, with **0/4 grounder false rejections**. Each decision went through database-backed `NormalKpiGrounder::record()` and checked evidence persistence. The cases cover a wrong period in the same row, Actual using a Target cell, conflicting currency and magnitude, duplicate period columns, a different count population, a subsidiary caption contradicting the parent banner, a multi-company banner, and the same value in an unrelated table. Existing DB regressions additionally cover DOCX wrong-period/wrong-column cells, merged headers, invalid text revision, explicitly contradictory model citations and row expansion across a blank table boundary. These synthetic denominators do not estimate performance on real documents or full analytical eligibility.

## Repeatability, latency and cost

The five flag-off runs agreed on model/expanded/link/proven/eligible counts, rejection reasons, gold coverage, charts, comparisons and API selection; the five flag-on runs also agreed on those signatures. The runner alternated test order to reduce warm-process bias. Parser-to-composite-API latency on this local fixture was **595–962 ms off (median 642 ms)** and **811–1,045 ms on (median 819 ms)**. These are five local runs per state with fake HTTP; they exclude database fixture setup and are not worker or production latency estimates. The frozen response records 8,209 input and 7,033 output tokens with a historical estimated provider cost of about **$0.004337**. This evaluation spent **$0** and made **zero paid inference calls**.

## Format-specific failures and readiness

- **PDF:** The Northstar table verifier proves 22/24 persisted observations' exact cells when the flag is on, but this PDF has simple aligned rows. PDF structure is still derived from flattened spans. The prior local Poppler experiment mapped only 1,085/1,325 geometry words exactly to stored text, leaving 240 unmapped; automatic region detection over-split tables. This gate did not rerun geometry and does not establish performance on wrapped, transposed, borderless, scanned or cross-page tables. Caption-dated regional/function breakdowns remain beyond the current row/column model.
- **DOCX:** Native grid proof and merged Actual/Target headers pass synthetic DB tests. No held-out DOCX report, frozen response or human cell gold was available for a format-level outcome.
- **XLSX:** Native sheet/cell extraction and source revision are tested synthetically. No held-out multi-sheet workbook with independent analytical gold was available.
- **Prose/contracts/scans:** No complete offline replay with human claim truth was available. Refusal or success rates are unknown.
- **Ownership and quantities:** Current banner/caption safeguards reject the reproduced parent/subsidiary exploits, but no real multi-entity corpus tests their generality. Four unitless Northstar counts remain analytics-ineligible because population and quantity kind are not independently proven as an analytical unit.

**Limited activation trial: not ready.** There are zero authorized real documents and only one fictional end-to-end response. The default-off flag is the correct setting. This gate shows a reproducible improvement on Northstar and preservation of the tested synthetic trust boundaries; it cannot show that either result generalizes.

## Highest-value next engineering work

1. **Add guarded DOCX/XLSX/general-PDF replay adapters and a 12–15-document authorized gold corpus** (high decision impact, medium evaluation engineering plus reviewer effort). This removes the current 0-real-document blocker and supplies measured format-specific false-acceptance and false-rejection denominators. Require source hashes, extraction revisions, cell/page positions, entity, period, basis, quantity, and negative claims before examining predictions.
2. **Model governing entity versus metric population as explicit structural relationships** (high correctness impact, medium-to-high cost). Synthetic parent/subsidiary exploits are closed, but the source-gold/model scope difference on the proposal and missing real multi-entity cases show that banner/caption rules alone cannot justify activation. Add adjudicated multi-entity fixtures before changing production resolution.
3. **Prove count populations and units, then evaluate PDF cell recovery with exact stored-text offsets** (four currently ineligible count observations are a measured low-to-medium-cost opportunity; reliable PDF cells are a larger, high-cost coverage limit). Do not promote generic unitless numbers or add a new PDF runtime until real-document failures justify it. Treat the PDF geometry path as a separate default-off experiment with abstention on unmapped offsets.

The next milestone is a versioned, independently reviewed, authorized corpus with offline replay adapters for at least one DOCX, one XLSX, one scanned/hybrid PDF, one multi-entity report, and one contract. Rerun this gate with per-format denominators before considering even a limited feature activation.

## Verification and changed files

- Final gate: five frozen replay pairs plus 22 synthetic adversarial decisions; passed. Restricted result: `storage/framework/testing/docintel-evidence-first-gate/gate-20261022-160533.json` and its `.md` companion, both mode `0600` and ignored by Git. They contain aggregate results and hashes, not the provider body.
- Complete safe backend suite: **1,844 tests, 1,830 passed, 14 skipped, 13,251 assertions**. The five PHP 8.5 `imagedestroy()` deprecations were already present in OCR code/tests. The opt-in V9 recorded evaluation passed **5 tests, 224 assertions**. Python scoring tests passed **7/7**. No test failed in the final runs; ordinary CI skipped the guarded V9/V10 evaluation methods as intended.
- New evaluation infrastructure: `tools/evidence_first_gate.py`, `tests/Evaluation/test_evidence_first_gate.py`, `tests/Feature/EvidenceFirstAdversarialGateTest.php`, and the three `tests/Fixtures/intelligence-v2/evidence-first-*.json` files. `tests/Feature/NorthstarV10LiveEvaluationTest.php` now records the flag state and asserts exact saved quote/cell offsets and source revision. This report is the only audit document added. Production classes and API contracts were not edited.
