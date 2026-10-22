# DocIntel — Independent architecture and intelligence-pipeline audit (V9)

- **Date:** 21 October 2026
- **Auditor role:** independent architecture / technical audit. **No application code, prompt, schema or data was modified.**
- **Repository state:** `CA_BACKEND` on branch `integration/v9-source-windows`, HEAD `8c8991b`, working tree preserved as found. Frontend audited at `CA` (`src/`).
- **Evidence base:** the repository as source of truth, the V9 live diagnostics (`storage/framework/testing/docintel-v9-evaluation/`), and **new offline reproductions** run during this audit against the real fixture PDF with zero paid model calls.
- **Reproduction scripts** were written to the session scratchpad (`/tmp/claude-1000/.../scratchpad/`), not to the repository, in line with the "only the audit report may be created" constraint. Part 6 specifies the equivalent tests that should become permanent regressions.

> **Note on the prior report.** `report-20261021-135636.md` is largely accurate and is used here as a starting point, not as a conclusion. This audit confirms several of its claims with code-level reproduction, **corrects one of its central framings** (Finding R-01 / §Part 1), and adds four findings it does not contain (R-02, R-07, R-08, R-10).

---

## Part 1 — Executive diagnosis

### What works

These are not assumptions; each was exercised during this audit.

1. **PDF extraction is adequate for this document class.** The fixture extraction is byte-reproducible: `DocumentTextExtractor::extractPdfText()` on `tests/Fixtures/Northstar_Q3_2026_Board_Performance_Review.pdf` yields SHA-256 `a4722cf…fa8da`, exactly the hash in the live diagnostics, and `SourceSpanBuilder` yields exactly 126 spans. Every table row, column header, page banner, caption and the page-7 glossary row is present and individually addressable. **No extractor replacement is justified by this evaluation.**
2. **The span/offset evidence substrate is sound.** `EvidenceSpanSet::resolve()` re-reads from `extracted_text` by offset, and `NormalKpiGrounder::span()` re-verifies that the stored span text still equals those exact characters (`NormalKpiGrounder.php:200-213`). Evidence cannot silently drift from its source.
3. **The tabular cell verifier genuinely works on the orientation it was built for.** I ran `PdfTabularCellVerifier::verify()` directly against the real span set for 13 synthetic claims. It correctly resolved individual cells for prior quarters, for target columns, and for a `≤ 90` ceiling, and correctly refused mis-oriented tables. Details in Part 3, R-01.
4. **Deterministic analytics are correctly conservative.** `ChartCandidateBuilder::build()` re-filters on `analyticalEligible` at its own entry point (`ChartCandidateBuilder.php:52-55`) rather than trusting its caller, and `AnalyticalComparisonBuilder` requires compatible measurement families, one granularity and one basis. Zero charts from one eligible observation is the correct output, not a bug.
5. **Persistence and serialization are intact.** One accepted candidate produced one evidence row with exact cell offsets, and `api_serialization_error` is null. The frontend does not silently promote unverified measurements: `measurementPresentation.ts:22-31` surfaces "Source unverified" / "Not eligible for analytical chart", and `Chart.tsx:234-235` degrades an ineligible chart to a labelled data table.

### What is broken

1. **The model's output contract asks for the wrong shape of output.** The V9 KPI schema (`DocumentInsightsPromptSeederV7.php:24`, extended by V8/V9) produces **one KPI per metric** — the latest period — and puts every other number into `charts` JSON, `trendValue` strings and prose. The deterministic builders need **two or more independently cited observations per group**. The two contracts have never been reconciled. This, not verification, is the dominant cause of zero charts.
2. **Four of the six rejections were avoidable with no code change at all**, and this is provable: the recorded-mode fixture in the harness already cites the page banner `E025` as the subject span and **is accepted** (`recorded-20261021-132306.json`: `rejection: null`, roles `value, unit, period, subject`). The live model cited the value row for the subject instead. The rejections are correct; the evidence that would have passed existed on the same page.
3. **A false-acceptance path exists that the Northstar fixture cannot detect.** `PdfTabularCellVerifier::verify()` returns `null` — "not a table claim", no cell proof — for any `table_row` span that is **not tab-delimited** (`PdfTabularCellVerifier.php:22`), while `SourceSpanBuilder::isTableRow()` classifies pipe-delimited and multi-space-delimited lines as `table_row` (`SourceSpanBuilder.php:161-176`). I built a space-aligned financial table and confirmed that a claim binding **FY2023 to the FY2025 value** passes every remaining check. See R-02. This is the most serious finding in this audit.
4. **Two breakdown tables in the fixture are structurally unverifiable.** The by-function and by-region cost tables put categories in the column headers and the period only in the caption. `verify()` returns `period_column_ambiguous` for every cell in them (reproduced). The "cost breakdown by function" composition chart is therefore impossible today **regardless of model behaviour**.
5. **Diagnostics actively mislead.** `NorthstarGoldScorer` scored the **raw provider payload** rather than the resolved candidate and reported 0/29 exact matches on a run whose facts were 7/7 correct; and `KpiResource` reports `source_revision_mismatch` on rows that simply have no evidence. Both are proven below.
6. **Ungrounded model chart JSON is persisted and shown to users.** `GenerateInsightsJob.php:231-270` writes the model's `charts` array to `document_charts` with no grounding and no `source_metadata`; the frontend renders those rows as origin `legacy` with the message "The origin of this historical chart is unavailable." The three Northstar charts — containing the very numbers the verified pipeline refused to produce — reach the user through the unverified channel, mislabelled as a historical-record problem.

### Why the Northstar evaluation produced no trusted analytics

Four independent gates, in order of impact:

| # | Gate | Facts lost | Nature |
|---|---|---|---|
| 1 | Model emitted one period per metric; prior quarters, targets and category breakdowns went to chart/trend/prose channels | ~20 of 29 gold cases | Output-contract design |
| 2 | Model cited the value row as the subject for 4 financial KPIs (`E029`–`E032`), not the page banner `E025` | 4 | Model citation selection — **correct rejection** |
| 3 | Cross-page glossary cannot establish a unit (`governs()` requires same page); plus an independently invalid subject | 1 | Evidence-contract limitation + citation failure |
| 4 | Concept matching for the proposal total ignores the already-reconstructed column header containing "funding" | 1 | Verifier defect — information lost between working components |

Even if gates 2–4 were all fixed, each financial series would still hold **one** point, and `ChartCandidateBuilder::timeSeries()` needs two (`ChartCandidateBuilder.php:100-106`). **Gate 1 is therefore necessary and nearly sufficient.** I confirmed offline that all twelve financial observations (4 metrics × Q1/Q2/Q3) and the 95 % delivery target ground successfully today with correct citations and no code change (R-01). That is four complete three-point series plus an actual-vs-target comparison plus the revenue − costs = profit reconciliation, available from a prompt/contract change alone.

### Verdict on the architecture

**Fundamentally sound, with targeted changes needed — not a redesign.**

The layering (extracted text → spans → model claims → field-role grounding → evidence rows → observations → deterministic builders → API) is the right decomposition, and the trust boundary is enforced in the right place. Three things are wrong and all three are local:

- the **output contract** between the model and the deterministic builders (the real blocker);
- a small number of **verifier defects and orientation assumptions** that are extensions, not rewrites;
- **diagnostic semantics** that made a working pipeline look like a broken model and a broken model look like a working pipeline.

No component identified in this audit needs to be replaced. The verification pipeline is **not** meaningfully overengineered in its responsibilities; it is under-specified at its inputs and over-fitted at its edges (Part 2).

---

## Part 2 — Architecture assessment

### Data flow as implemented

```
Upload
  └─ ExtractDocumentTextJob → DocumentTextExtractor::extractPdfStructured()   [Smalot\PdfParser]
        └─ extracted_text (+ StructuredDocument for XLSX/DOCX)
             └─ EvidenceGrounding::spans() → SourceSpanBuilder::build()
                   └─ document_source_spans  (E001…E126, typed: table_row | heading | list_item | sentence | prose | section)
                        └─ prompt window (span ids shown to the model)
                             └─ AnthropicClient::extractDocumentInsights()  [prompt document_insights v9]
                                  └─ {kpis[{label,value,unit,trend,trendValue,source_context{4 span ids},identity{7 fields}}], charts[], insights[]}
                                       ├─ KpiIdentityResolver::forDocument()  → kpi_definition_id, period  (derives period from identity)
                                       ├─ GenerateInsightsJob → document_kpis
                                       │     └─ NormalKpiGrounder::record()
                                       │           ├─ PdfTabularCellVerifier::verify()   (PDF only)
                                       │           ├─ field-role loop: value / unit / period / subject
                                       │           ├─ ValueBasisEvidence::rejection()
                                       │           └─ accept → document_evidence(kind=metric, sources[], roles[])
                                       │                 reject → identity_metadata.grounding_rejection
                                       └─ charts[]  → document_charts / document_chart_points   ** NO GROUNDING **
                                            └─ MetricCollector::collect()  (evidence rows win; unevidenced KPI rows still become observations)
                                                  └─ MeasurementIntegrity::assess() → analyticalEligible
                                                       ├─ ChartCandidateBuilder::build()        (≥2 eligible per groupKey)
                                                       ├─ AnalyticalComparisonBuilder::build()  (actual-vs-target/budget, period change, financial reconciliation)
                                                       └─ DocumentAnalysisComposer::compose() → analysis{}
                                                             ├─ DocumentIntelligenceResource  → analysis + evidence map
                                                             └─ DocumentResource.charts → ChartResource   ** serves the ungrounded model charts **
Frontend (CA)
  ├─ UnifiedCharts.tsx          merges analysis.visualAnalysis.charts ('generated') with doc.charts ('document' | 'legacy')
  ├─ measurementPresentation.ts renders per-KPI limitations
  └─ chartPromotion.ts          layout only, no scoring
```

### Strengths worth preserving

| Strength | Where | Why it matters |
|---|---|---|
| Offset-anchored evidence, re-verified on read | `EvidenceSpanSet`, `NormalKpiGrounder::span()` | Makes "this number is on page 3 at these characters" a checkable claim |
| Per-role proof (`value`/`unit`/`period`/`subject`) with a precise rejection code per role | `NormalKpiGrounder::record()` | Rejections are diagnosable; the code tells you which relationship failed |
| Exact cell resolution, no adjacent-column tolerance | `PdfTabularCellVerifier` | Refuses "the same number appears somewhere in the row" |
| Trust boundary re-asserted by consumers, not just producers | `ChartCandidateBuilder.php:52-55`, `AnalyticalComparisonBuilder.php:16-18` | A new caller cannot promote an unverified observation |
| One collector for both routes, preserving rejected rows as ineligible observations | `MetricCollector::records()` | Rejections stay visible to the UI rather than vanishing |
| Exact decimal arithmetic (`Brick\Math\BigDecimal`), no currency conversion, no scale guessing outside `MeasurementParser` | `PdfTabularCellVerifier`, `MeasurementParser` | Avoids a whole class of silent numeric corruption |
| Honest frontend degradation of ineligible charts to data tables | `chartLayout.ts:166-177`, `Chart.tsx:234-235` | The UI does not assert verification it does not have |

### Confirmed defects

R-01 … R-10 in Part 3. Summary: one high-severity false-acceptance path (R-02), two verifier/contract limitations that block real evidence (R-04, R-05), one verifier defect that discards evidence it already computed (R-06), two misleading-diagnostic defects (R-07, R-08), one trust-channel leak (R-10), plus the dominant output-contract gap (R-01/R-03).

### Unnecessary complexity (answering question A directly)

The verification pipeline is **not** overengineered in its decomposition. It is, however, carrying three kinds of avoidable complexity:

1. **Duplicated integrity post-processing.** `MetricCollector::observe()` (`MetricCollector.php:276-292`) and `KpiResource::toArray()` (`KpiResource.php:38-54`) each independently append `period_unavailable` and `semantic_identity_conflict` after calling the same `MeasurementIntegrity::assess()`. They have already drifted: `KpiResource` de-duplicates `reasons`, `MetricCollector` does not, and `KpiResource` passes a `current_source_revision` that `MetricCollector` deliberately omits for unevidenced rows — which is exactly R-07. **One post-assessment function, called by both, removes the defect and the duplication together.**
2. **Fixture-shaped lexical rules inside a generic verifier.** `PdfTabularCellVerifier` contains a concept-word stoplist `['quarterly','rate','received','cases']` (`:89`) and a literal `\btotal\s+proposed\b` special case (`:99`). These exist to make *this* document pass. They are not generalizable and they are the wrong mechanism (Part 5 proposes replacing them with a structural rule).
3. **Two parallel field-verification implementations.** `NormalKpiGrounder` (normal route, 390 lines) and `IncrementalMetricFieldVerifier` + `SourceProvenanceReader` (incremental route) solve the same problem — prove that each claimed field is supported by a cited span — with different code, different rejection vocabularies and different relationship rules. `MeasurementIntegrity` then has a route-specific branch (`field_verification_policy === 'incremental.v1'`, `:84-94`) to reconcile them. This is real duplicated responsibility, but **it is not what broke Northstar** and consolidating it is a medium-cost, low-urgency change. It is ranked last in Part 4 deliberately.

What is **not** overengineering: the four separate role proofs, the separate `analyticalEligible` gate, the re-filter inside the builders, and the refusal to accept adjacent columns. Every one of those earned its place during this audit by producing a correct answer.

### Missing capabilities

- No way for the model to emit a **complete observation set** for an intended chart or comparison (no target/ceiling operand, no prior-period operand).
- No verification support for **transposed tables** (categories in headers, period in caption).
- No verification support for a **bare-quarter column** whose year appears only in a *different* quarter's page header — which silently caps every operational series at one point.
- No **document-local metric-definition binding** (glossary / "definitions" page), a near-universal feature of real reports.
- No **cell proof for DOCX tables**: `NormalKpiGrounder.php:38-41` gates `PdfTabularCellVerifier` on `$document->type === 'PDF'`, and only XLSX has its own structural path (`xlsxRelationship()`). DOCX tables fall back to prose-strength proof.
- No **two-column (label | value) table** support: `count($cells) < 3` is rejected as `table_column_mismatch` (`PdfTabularCellVerifier.php:33`), yet label/value tables are extremely common.
- No regression test for `NorthstarGoldScorer` at all (grep: it is referenced only by the evaluation test), which is why R-08 survived.

### Dangerous architectural assumptions

| Assumption | Where | Failure mode |
|---|---|---|
| A table row worth verifying is tab-delimited | `PdfTabularCellVerifier.php:22` | **Silent downgrade to prose proof → false acceptance (R-02)** |
| A period always lives in a column header | `:57-76` | Transposed/captioned tables unverifiable (R-05) |
| A governing entity header is the **first span on its page** | `NormalKpiGrounder::pageHeaderSubject()` `:262-275` | Any page starting with a page number or footer can never prove a subject |
| A governing relationship is same-page and preceding | `::governs()` `:241-259` | Glossaries, appendices, continuation pages unusable (R-04) |
| Every quarter on a page shares the page header's year | `::quarterPageContext()` `:278-307` | Only the page's own quarter gets a full period (R-03b) |
| Any basis keyword anywhere in the cited row describes *this* value | `ValueBasisEvidence::rejection()` | A row reading "On-time deliveries (target 95 %) 87 %" rejects the *actual* as `value_basis_source_mismatch` |
| Model chart JSON is harmless if the deterministic path ignores it | `GenerateInsightsJob.php:231` | Ungrounded numbers reach the UI (R-10) |

---

## Part 3 — Root-cause findings

Severity: **S1** blocks trustworthy output or can emit a wrong number · **S2** loses correct evidence or misleads the operator · **S3** limits generality or maintainability.
Confidence: **Proven** (reproduced in this audit) · **Confirmed-by-artifact** (established by the live/recorded diagnostics plus code reading) · **Hypothesis**.

---

### R-01 — Verified analytics are blocked by model output coverage, not by verification

- **Severity:** S1 · **Confidence:** Proven
- **Symptom:** 7 correct facts, 1 verified, 0 charts, 0 comparisons. The prior report attributes the primary loss to citation selection and verifier restrictions.
- **Root cause:** The KPI schema (`database/seeders/DocumentInsightsPromptSeederV7.php:24`, extended at `V8:25` with `source_context` and at `V9` with rule 17) requests one KPI per metric with a single `identity.period`, and separately requests free-form `charts`. The deterministic builders require ≥ 2 eligible observations sharing a `groupKey()` (`ChartCandidateBuilder.php:100-106`) and, for `actual_vs_target`, one `actual` **observation** and one `target` **observation** in the same group (`AnalyticalComparisonBuilder.php:30-40`). Nothing in the contract asks the model to supply those operands as observations, so they arrive as chart JSON and prose and are discarded.
- **Reproduction (new, offline, no model call):** driving the **real** `PdfTabularCellVerifier` against the **real** 126-span set rebuilt from the fixture PDF (text SHA-256 matches the live run exactly):

  | Claim | Citations | Result |
  |---|---|---|
  | Revenue Q1 2026 = 42.0 | `E029` / `E028` | **ACCEPTED** (cell `42.0`, col `Q1 2026`) |
  | Revenue Q2 2026 = 46.0 | `E029` / `E028` | **ACCEPTED** (cell `46.0`, col `Q2 2026`) |
  | Operating costs Q1 2026 = 33.0 | `E030` / `E028` | **ACCEPTED** (cell `33.0`, col `Q1 2026`) |
  | Operating margin Q1 2026 = 21.4 % | `E032` / `E028` | **ACCEPTED** (cell `21.4%`, col `Q1 2026`) |
  | **On-time delivery Q3 2026 target = 95 %** | `E048` / `E046` | **ACCEPTED** (cell `95%`, col `Q3 target`) |
  | Complaints ceiling Q3 2026 = ≤ 90 | `E049` / `E046` | **ACCEPTED** (cell `≤ 90`, col `Q3 target`) |
  | Shipments Q3 2026 = 14,900 | `E047` / `E046` | **ACCEPTED** (cell `14,900`, col `Q3`) |
  | Employees Q3 2026 = 123 | `E050` / `E046` | **ACCEPTED** (cell `123`, col `Q3`) |

  Period-role resolution was then checked by reflection against the live grounder: for the financial table the period role resolves directly because `E028` literally contains `Q1 2026`/`Q2 2026`/`Q3 2026`; and `pageHeaderSubject(E025 → E029) = true`, so the subject role resolves from the page banner. **All twelve financial observations and the 95 % target are fully groundable today.** This is independently corroborated by the harness's own recorded fixture, which cites `E025` as subject and is accepted with all four roles verified.
- **User-visible impact:** a board pack whose every number is extractable yields one KPI card and no charts. The user's only visible trend data comes from the unverified channel (R-10).
- **Recommended correction:** extend the output contract so every intended chart point and comparison operand is an **independently cited observation** carrying its own `source_context` and `identity` (including `value_basis: target` and the ceiling operator), and stop asking the model for chart JSON. Keep the deterministic builders unchanged.
- **Risks:** more output tokens (the V9 response was 5,196 output tokens at $0.0034; a complete observation set for this document is roughly 25–30 KPIs, so budget ~2–3× output cost); duplicate observations; scope/basis confusion between an actual and its target. Mitigations: explicit de-duplication on `(concept, scope, period, value_basis)`, and the existing `ValueBasisEvidence` gate already refuses a mislabelled basis.
- **Confidence:** Proven for groundability; the token/latency effect and whether the model will comply require one authorized paid run.

---

### R-02 — Non-tab-delimited table rows bypass cell verification entirely and can bind a value to the wrong period

- **Severity:** S1 (false acceptance — a wrong number can enter a trusted chart) · **Confidence:** Proven at the check level; one DB-backed test needed for end-to-end confirmation
- **Symptom:** Not observed in the Northstar run, because this fixture's extraction happens to emit tabs. Invisible to the entire existing test suite.
- **Root cause:** Two components disagree about what a table row is.
  - `SourceSpanBuilder::isTableRow()` (`SourceSpanBuilder.php:161-176`) classifies a line as `table_row` if it contains a tab, **or two or more `|` characters, or three or more columns separated by two-or-more spaces** with figure-like endings.
  - `PdfTabularCellVerifier::verify()` (`PdfTabularCellVerifier.php:22`) returns **`null`** — meaning "not a table claim", no rejection — unless the value span contains a literal `\t`.
  - `NormalKpiGrounder::record()` treats `$tableProof === null` as the prose path: value proof becomes "the number occurs exactly once in the cited span" (`containsValue()`), concept proof becomes "two concept words appear in the span" (`containsConcept()`), and the period role is proved by `governs()` — which checks only **page, ordering and span type**, never column alignment (`NormalKpiGrounder.php:241-259`).
- **Reproduction (new):** a space-aligned table was built and passed through the real `SourceSpanBuilder` and the real grounder helpers:

  ```
  ACME HOLDINGS PLC  |  ANNUAL REPORT  |  FY2025        → E001 table_row
  Measure        FY2023   FY2024   FY2025               → E003 table_row
  Revenue        42.0     46.0     51.0                 → E004 table_row
  ```

  Claim: *Revenue, FY2023, 51.0 USD million* — i.e. the **FY2025 value labelled FY2023**, citing `E004` (value), `E003` (period), `E002` (unit), `E001` (subject).

  ```
  verify()                          => NULL (no cell proof; prose fallback applies)
  containsValue(E004, '51.0')       => true
  containsConcept(E004, 'revenue')  => true
  containsWords(E003, 'FY2023')     => true
  governs(E003, E004)               => true
  pageHeaderSubject(E001, E004)     => true   (E001 is the first span on the page)
  ```

  Every remaining gate (`MeasurementParser` currency/scale, `ValueBasisEvidence` for `actual`, `PeriodParser` for `FY2023`) passes for this input. The candidate would be persisted with `source_fields_verified = [value, unit, period, subject]`, become `analyticalEligible`, and be eligible for a trusted chart with a wrong period→value binding.
- **User-visible impact:** a trusted chart or comparison containing a number attached to the wrong period, carrying full "verified" provenance. This is the one failure mode the whole architecture exists to prevent.
- **Recommended correction:** make the gate explicit rather than implicit. For a span typed `table_row`, `verify()` must **either** produce a cell proof **or** return a rejection — never `null`. Teach `columns()`/`verify()` the same delimiters `isTableRow()` accepts (tab, `|`, and the 2+-space column split), reusing the existing column logic; if a row is typed `table_row` but cannot be split into columns consistent with its header, reject (`table_column_mismatch`). The prose path must remain reachable only for spans **not** typed `table_row`.
- **Risks:** medium. Previously-accepted space-delimited claims may start to be rejected, lowering coverage on some existing documents. That is the correct direction (a rejection is recoverable; a wrong verified number is not), but it should ship with a count of newly-rejected rows measured on a corpus, not just on Northstar. The 2+-space split is itself heuristic; widening it incautiously could mis-split a prose line that `isTableRow()` already mis-typed, which is why the rule should be "same splitter as the classifier, or reject".
- **Additional test needed for full proof:** a DB-backed feature test that runs `NormalKpiGrounder::record()` end to end on the space-delimited document above and asserts that today it writes a `document_evidence` row with all four roles verified (red), and after the fix returns a rejection (green).

---

### R-03 — Prior-period and target operands exist in the source but cannot all reach the builders

This finding has two distinct parts; they must not be conflated.

#### R-03a — Financial prior quarters: *no blocker at all*

- **Severity:** S1 (highest-value opportunity) · **Confidence:** Proven
- The financial table's header `E028` is `Measure\tQ1 2026\tQ2 2026\tQ3 2026`. Every column names a full quarter and year, so the period role resolves by direct `containsWords()` match with no page-header inference. Combined with R-01's accepted cell proofs, **all 12 financial observations are groundable today.** The only missing ingredient is that the model never emitted them.
- Consequence: revenue/costs/profit/margin each become a 3-point time series; `AnalyticalComparisonBuilder::financialReconciliations()` (`:76-92`) recognises exactly the concepts `revenue`, `operating costs`, `operating profit`, so the operating-profit reconciliation also becomes available; and `period_change` comparisons appear for each series.

#### R-03b — Operational prior quarters: a genuine evidence-contract limitation

- **Severity:** S2 · **Confidence:** Proven
- **Symptom:** delivery and complaints can never form a series, even with perfect citations.
- **Root cause:** the operational header `E046` is `Operational indicator\tQ1\tQ2\tQ3\tQ3 target` — **bare quarters, no year anywhere in the table**. The year exists only in the page banner `E043` (`… | Q3 2026 | Page 4`). `NormalKpiGrounder::quarterPageContext()` (`:278-307`) proves a full period only when the cited column's quarter **matches the quarter named in the page header**, so it can rescue Q3 and nothing else.
- **Reproduction (new, reflection against the live grounder):**

  | Claim | Table proof | Period in header? | `quarterPageContext` | Period role |
  |---|---|---|---|---|
  | delivery Q1 2026 = 92 % | ACCEPTED | no | `null` | **`period_source_mismatch`** |
  | delivery Q2 2026 = 90 % | ACCEPTED | no | `null` | **`period_source_mismatch`** |
  | delivery Q3 2026 = 87 % | ACCEPTED | no | `E043` | OK |
  | delivery Q3 2026 target = 95 % | ACCEPTED | no | `E043` | OK |
  | complaints Q1 2026 = 84 | ACCEPTED | no | `null` | **`period_source_mismatch`** |
  | revenue Q1 2026 = 42.0 | ACCEPTED | **yes** (`E028`) | n/a | OK |

- **User-visible impact:** delivery and complaints are permanently single-point metrics, so "delivery over time" can never be charted even though the actual-vs-target comparison can.
- **Recommended correction:** this is the one place where a *bounded* inference is defensible and it should be decided deliberately, not silently. Two honest options: (i) extend `quarterPageContext()` so a page header establishing `Q<n> <year>` also establishes the **year** for the sibling bare-quarter columns of the *same* table, provided no other explicit year appears on the page before the cited row (the existing conflict scan at `:299-307` already implements that guard) and the quarter ordering in the header is consistent; or (ii) leave it as is and accept single-point operational metrics. Option (i) is a real inference — "Q1 in a Q3 2026 pack means Q1 2026" — and must be recorded in the evidence `roles` so it is auditable, and must be refused the moment the page names a second year.
- **Risks:** medium. A multi-year comparison table (`Q1` columns for 2025 and 2026) must not be collapsed; the existing conflicting-year scan mitigates but does not fully cover a table whose years appear only in a second header row.
- **Note:** the prior report's recommendation 2 does not distinguish R-03a from R-03b. The distinction matters because R-03a needs **no code change** and R-03b needs a deliberate inference decision.

---

### R-04 — Cross-page metric definitions cannot establish a unit

- **Severity:** S2 · **Confidence:** Proven
- **Symptom:** candidate 6 rejected `unit_relationship_unverified`; the unit `cases` is factually correct and explicitly defined in the document.
- **Root cause:** `E114` (page 7) is `Customer complaints\tNumber of cases received in quarter; not unique customers`; the value row `E049` is on page 4. `NormalKpiGrounder::governs()` (`:241-249`) requires `$heading['page'] === $value['page']` and `$heading['start_offset'] < $value['start_offset']`. Reproduced: `governs(E114 → E049) = false`.
- **Also true, and important:** this candidate's **subject** citation is independently inadequate — `identity.scope` is `"cases received"` cited to `E049`, which does not contain that phrase, so it would fail `subject_source_mismatch` immediately after. **Fixing the glossary alone does not rescue candidate 6.** The prior report states this correctly and it is confirmed here.
- **User-visible impact:** a correctly-defined count metric is excluded from analytics; the limitation shown to the user (`unit_unverified`) suggests an invented unit, which is wrong.
- **Recommended correction:** a bounded, document-local **definition binding**: accept a `table_row`/`list_item` span on any page as the unit/definition source **only** when (a) its leading cell matches the value row's leading cell under the existing singularisation rule, (b) exactly one such span exists in the document, (c) it is not itself a value row for the same metric, and (d) the stored source revision matches. Record it as its own entry in `sources[]` with role `unit`, so the cross-page link is visible in the API evidence map. Do **not** relax `governs()` generally.
- **Risks:** medium-high. Duplicate or conflicting glossary entries, a definitions table that repeats the metric label for a different population, and appendix restatements all need explicit refusal. Uniqueness (b) is the load-bearing guard.

---

### R-05 — Transposed / caption-dated tables are structurally unverifiable

- **Severity:** S2 (and S3 for generality) · **Confidence:** Proven
- **Symptom:** the four cost-by-function values (17/12/7/5) and three region values (21/16/14) exist only in the model's unverified chart JSON. No composition or categorical chart is possible.
- **Root cause:** `PdfTabularCellVerifier` assumes **row label + period columns**. The fixture's breakdown tables are the other orientation:

  ```
  E038 sentence   Q3 operating costs by function (KES million)      ← the period lives here
  E039 table_row  Logistics  Payroll  Warehousing  Technology  Total ← categories, not periods
  E040 table_row  17.0  12.0  7.0  5.0  41.0                         ← values, no row label
  ```

  The column loop (`:57-76`) requires a column matching `/^Q3(\s|$)/`; none exists, so `count($indexes) !== 1` → `period_column_ambiguous`. Additionally `$cells[0]` is `17.0`, so the concept check (`:97-101`) could never match a category name even if the period resolved.
- **Reproduction (new):** `cost function Logistics Q3` (`E040`/`E039`) → `period_column_ambiguous`; `region North Q3` (`E037`/`E036`) → `period_column_ambiguous`; `cost total Q3` from the function table (`E040`/`E039`) → `period_column_ambiguous`.
- **User-visible impact:** the composition chart a board pack most obviously supports cannot be produced. Generally: any "X by category for period P" table — ubiquitous in real reports — is unverifiable.
- **Recommended correction:** add a second, explicitly-named orientation to the same verifier rather than a new component: a **header-category table**, where the cited header row supplies the *subject/category* for the cited column and the period comes from the immediately preceding caption span (`precedingCaption()` already exists, `:128-141`) which must name exactly one period. The value row may have no leading label; the subject role then resolves to the header cell at the same column index. Emit a distinct rejection code (`category_column_unverified`) so the two orientations stay diagnosable.
- **Risks:** medium. Two tables under one caption, a caption naming two periods, and a header row that mixes periods and categories must all be refused. Requires the caption→table adjacency to be unambiguous.

---

### R-06 — Proposal-total concept check discards the column header it already reconstructed

- **Severity:** S2 · **Confidence:** Proven (code path) / Confirmed-by-artifact (live rejection)
- **Symptom:** candidate 7 rejected `concept_row_mismatch` for a correctly extracted proposed total.
- **Root cause:** `identity.concept` is `proposed service-recovery funding`; the row label `$cells[0]` is `Total proposed`. The fallback at `PdfTabularCellVerifier.php:97-101` requires every concept word to appear in the **caption** (`E071`, `Proposed service-recovery budget`). `funding` appears in the **header** `E072` (`Initiative\tProposed funding (KES`) — which `columns()` has **already** merged with its wrapped continuation into `Proposed funding (KES million)` and holds in `$columns`. The check simply never looks at `$columns`. This is information lost between two functioning components inside one method.
- **Reproduced in the live artifacts:** `source-review-20261021-135636.json` → `kpi:7` → `"rejection": "concept_row_mismatch"`, with `unit_span_id` text `Initiative\tProposed funding (KES`.
- **Secondary:** the surrounding code is the fixture-shaped special case `\btotal\s+proposed\b` (`:99`) plus the stoplist at `:89`. Both are Northstar-specific.
- **User-visible impact:** a clearly-sourced proposal total is dropped. (It is periodless, so it would not become a chart point regardless — `MetricCollector` appends `period_unavailable` and clears eligibility, `MetricCollector.php:276-280`. It belongs on a KPI card, not a series.)
- **Recommended correction:** evaluate the concept against the union of {row label, verified caption, **the matched column header from `$columns`**}, requiring the match to come from spans already proven to belong to this table. Replace the `\btotal\s+proposed\b` literal with the structural condition it stands for — "the cited row is a total row of this table" — and replace the stoplist with a generic stop rule (drop words that are period words or the unit's own words), so `quarterly` and `cases` are excluded by category rather than by name.
- **Risks:** medium. A looser concept union could join an unrelated total to a nearby caption; the guard is that every contributing span must already be a verified part of **this** table (header, its continuation, or the immediately preceding caption).

---

### R-07 — `source_revision_mismatch` is reported for rows that simply have no evidence

- **Severity:** S2 (misleading diagnostic, user-visible) · **Confidence:** Proven
- **Symptom:** `live-…json` → every rejected candidate's `api_kpi.limitationReasons` contains `source_revision_mismatch`, e.g. candidate 1: `[missing_source_reference, source_revision_mismatch, unit_unverified, subject_source_mismatch]`. The same candidate's `integrity.reasons` (from `MetricCollector`) does **not** contain it.
- **Root cause:** `KpiResource.php:34-36` always supplies `current_source_revision = hash(extracted_text)` while supplying `source_revision = $evidenceData['source_revision'] ?? null`. In `MeasurementIntegrity::assess()` (`:41-43`), `$revisionMatches` is true only when `current` is null **or** `source_revision` is a matching string; a null stored revision with a non-null current revision therefore evaluates to **mismatch**. `MetricCollector` avoids this by not setting `current_source_revision` for unevidenced KPI rows (`MetricCollector.php:150-153`), which is why the two paths disagree.
- **User-visible impact:** "the document changed since this measurement was extracted" is shown for a measurement that was never verified at all. It invites an operator to re-extract a document that is fine, and it masks the real reason.
- **Recommended correction:** distinguish three states explicitly — `revision_proof_missing`, `revision_matches`, `revision_conflict` — and emit `source_revision_mismatch` only for a conflict, i.e. a stored revision that exists and differs. Do this inside `MeasurementIntegrity` so both callers inherit it, and fold the duplicated post-assessment logic (`period_unavailable`, `semantic_identity_conflict`, reason de-duplication) into one shared helper at the same time.
- **Risks:** low. `analyticalEligible` must not change: `sourceVerified` already requires `$fieldProof !== null`, so a missing-proof row stays ineligible on its own merits.

---

### R-08 — `NorthstarGoldScorer` scores the raw provider payload, reporting 0/29 on a 7/7-correct run

- **Severity:** S2 (evaluation integrity — this defect caused the run to be misread) · **Confidence:** Proven
- **Symptom:** `live-…json` → `source_gold_score.exact_gold_matches = 0`, while `recorded-20261021-132306.json` → `exact_gold_matches = 1`.
- **Root cause (more specific than the prior report's):** `NorthstarGoldScorer::matches()` (`tests/Support/NorthstarGoldScorer.php:40-41`) reads `$candidate['period']`. The V9 wire schema has **no top-level `period`** — `DocumentInsightsPromptSeederV7.php:24` places it at `identity.period` only, and the live payload confirms it. The top-level `period` is created later, in production code, by `KpiIdentityResolver::forDocument()` (`app/Services/Kpis/KpiIdentityResolver.php:32`), on the array the **job** holds. The test scores `$result['kpis'][$index]` — the pre-resolution payload — so the field is always absent and every period comparison fails.
- **Why it was never caught:** the harness's own fake candidate builder injects a top-level `period` (`tests/Feature/NorthstarV9EvaluationTest.php:419`), so recorded mode matches and live mode does not. There is **no unit test for `NorthstarGoldScorer`** anywhere in `tests/`. The fixture drifted from the contract it is supposed to measure, and nothing compared them.
- **User-visible impact:** none directly; but the operator-visible impact is severe — a benchmark reporting zero model accuracy for a run that was factually perfect.
- **Recommended correction:** score the **resolved** candidate (identity-aware): read `identity.period` with the top-level `period` only as a fallback. Keep the strict label/subject string comparison but report label-and-scope near-misses as a separate `review_required` bucket instead of silently counting them as non-matches. Make the harness's fake candidate conform exactly to the V9 schema (no invented top-level `period`) so recorded mode can never again pass where live mode fails.
- **Risks:** low — it is test tooling. The one real risk is inflating coverage by loosening subject matching; keep that in a separate reviewed bucket, never in `exact_gold_matches`.

---

### R-09 — `pageHeaderSubject` requires the governing header to be the literal first span on its page

- **Severity:** S3 · **Confidence:** Proven
- **Symptom:** none in this fixture — `E025` and `E043` happen to be the first spans on pages 3 and 4 (`pageHeaderSubject(E025 → E029) = true`, `(E043 → E048) = true`).
- **Root cause:** `NormalKpiGrounder::pageHeaderSubject()` (`:262-275`) iterates `$set->all()`, takes the **first** span whose page equals the value's page, and returns true only if that span is the cited heading. Any document whose pages begin with a page number, a confidentiality footer, a logo caption or a continued-table row can therefore **never** prove a subject from its page header — the only mechanism the grounder offers for an entity that is not inside the value row.
- **User-visible impact:** on such documents every entity-scoped KPI fails `subject_relationship_unverified` regardless of citation quality, i.e. R-01's primary recovery path silently disappears.
- **Recommended correction:** accept any span on the same page that precedes the value span **and** is typed `heading`/`section`/`table_row`, provided it is the only span on that page containing the claimed scope. Keep "exactly one candidate" as the guard rather than "first".
- **Risks:** low-medium. Two different entity names on one page must produce a refusal, not a pick.

---

### R-10 — Ungrounded model chart JSON is persisted and rendered, mislabelled as a historical-origin problem

- **Severity:** S2 (trust channel) · **Confidence:** Proven by code path; frontend not executed in this audit
- **Symptom:** the Northstar run produced "zero trusted charts" — yet the model's three charts (revenue 42/46/51, delivery 92/90/87, cost functions 17/12/7/5) are written to the database and served to the client.
- **Root cause:** `GenerateInsightsJob.php:231-270` creates `DocumentChart` + `DocumentChartPoint` rows straight from `$result['charts']` with no grounding, no `source_context` and no `source_metadata`. Only `AnalyzeEmbeddedVisualsJob.php:217` ever sets `source_metadata`, so insights-authored charts have `source_metadata = null` → `ChartResource` computes `$source = null`, `renderEligible = false`, `unavailabilityReason = "The origin of this historical chart is unavailable."`, **but still returns `data`**. `DocumentResource.php:64` ships them; `UnifiedCharts.tsx:116-117` maps `sourceOrigin !== 'document_visual'` to origin **`legacy`**, labelled "Legacy chart · origin unavailable", and `chartLayout.ts:177` degrades them to a data table with that same message.
- **User-visible impact:** two problems. (a) The only trend numbers the Northstar user sees come from an unverified channel. (b) The explanation is wrong: these charts are not historical records of unknown origin, they are **this run's** model output with no citations. A reader cannot tell "we lost the provenance of an old chart" from "the model asserted these numbers and we never checked them". The one mitigation is real: they render as a table, not as a chart, and are not counted as verified.
- **Recommended correction:** decide the channel deliberately. Preferred, and consistent with R-01: stop requesting `charts` from the model and stop persisting them; the deterministic builder owns charts and `AnalyzeEmbeddedVisualsJob` owns real document visuals. If they are retained as a transitional fallback, give them an explicit origin (e.g. `source_metadata.origin = 'model_unverified'`) and a truthful message ("Generated by the model from the document text; the individual values were not verified against the source"), and keep them out of `exportSupport.word`/CSV until verified.
- **Risks:** low-medium. Removing them reduces visible output on documents that currently produce no deterministic charts — which is exactly the Northstar case, so it should land **after** R-01 so the deterministic path has something to show.

---

### Explicitly not defects

- **The four financial `subject_source_mismatch` rejections are correct.** `E029`–`E032` contain no entity name; the grounder's refusal is the system working. The recovery is better citation selection, proven to be accepted (R-01).
- **Zero charts from one eligible observation is correct.** `insufficient_points: 1`, `single_category: 1` is the right answer to the input it received.
- **No extractor defect was demonstrated.** Every number, header, caption and glossary row needed for 29/29 gold facts is present in the extracted text.
- **No confirmed false acceptance occurred in the live run.** R-02 is a latent path this fixture cannot reach.

### Areas not exercised by this audit

- The frontend was **not executed**; findings about it are code-reading plus the live payload shape.
- Revision publication was not tested (the harness runs `document_intelligence.revision_v1 = false`).
- The **incremental** route's field verification (`IncrementalMetricFieldVerifier`, `SourceProvenanceReader`, `EvidenceMerger`, the 49 KB `IncrementalPipeline`) was read for duplication only, not audited for correctness.
- OCR / image paths (`OcrPageBatchJob`, `ImageRegionLocator`, `AnalyzeEmbeddedVisualsJob`), the B2 brief (`BriefVerifier`, 42 KB), materiality scoring, credits/billing and entitlements were not audited.
- XLSX and DOCX grounding were read but not reproduced; the DOCX gap in Part 2 is a code-reading finding.
- No document other than the Northstar fixture and my synthetic space-table was processed, so no corpus-level false-acceptance or false-rejection rate is established by this audit.

---

## Part 4 — Ranked engineering changes

Priorities are ordered by (evidence integrity) then (verified output per unit of work). P1 and P2 are independent and can proceed in parallel; everything else depends on one of them.

---

### P1 — Close the non-tab table-row verification bypass

- **Objective:** make it impossible for a `table_row` span to be accepted without a cell proof.
- **Files:** `app/Services/Intelligence/PdfTabularCellVerifier.php` (`verify()` :20-33, `columns()` :107-126); `app/Services/AI/Incremental/SourceSpanBuilder.php` (`isTableRow()` :161-176, **read-only** — it defines the delimiter set to reuse); `app/Services/Intelligence/NormalKpiGrounder.php` (:38-48, the `$tableProof === null` branch).
- **Exact behaviour to change:** extract the delimiter/column-splitting rule used by `isTableRow()` into a single shared helper and use it in `verify()`. When the value span's `type` is `table_row`, `verify()` must return either a cell proof or a rejection; it may return `null` **only** for a span not typed `table_row`.
- **Must remain unchanged:** every currently-accepted tab-delimited claim (Northstar `kpi:1`–`kpi:6` table proofs); the refusal to accept an adjacent column; `source_value`/`cell_start_offset`/`cell_end_offset` semantics; the genuine prose path for `sentence`/`prose`/`list_item` spans.
- **Acceptance criteria:** the space-delimited FY2023↔51.0 claim in R-02 is rejected; all 13 reproduction cases in R-01/R-05 keep their current verdicts; `./vendor/bin/phpunit` green.
- **Positive tests:** space-aligned and pipe-delimited tables accept the *correct* cell for each column; wrapped-header continuation still works.
- **Negative tests:** the R-02 wrong-period claim; a `table_row` whose columns cannot be aligned with its header → `table_column_mismatch`; a `sentence` span still takes the prose path.
- **Dependencies:** none. **Complexity:** medium.
- **Risk / benefit:** may reduce acceptance on existing non-tab documents (measure the delta before shipping); removes the one proven path to a wrong verified number.

---

### P2 — Make analytical operands explicit, independently cited observations

- **Objective:** have the model emit one cited observation per intended chart point and comparison operand, so the existing builders have something to build from.
- **Files:** a **new** `DocumentInsightsPromptSeederV10` (V9 is immutable and seeders are additive — do **not** edit V9); `app/Jobs/GenerateInsightsJob.php` (:198-229, de-duplication on ingest); `app/Services/Kpis/KpiIdentityResolver.php` (unchanged contract, verify it handles ~30 candidates); `app/Services/Intelligence/NormalKpiGrounder.php` (**unchanged**).
- **Exact behaviour to change:** the output contract must require (a) one KPI object per (concept, scope, period, value_basis) the document supports and the response intends to compare, each with its own `source_context` and `identity`; (b) `value_basis: target` for target/ceiling operands, with the comparison operator preserved in the value text (`≤ 90`); (c) removal of the `charts` array from the requested schema. Rule 17's citation guidance should additionally state, generically, that an entity scope must be cited to the span that names the entity (a page banner or section header), never to the value row — the single change that recovers the four financial KPIs.
- **Must remain unchanged:** all grounding, integrity and builder logic; `trend`/`trendValue` may remain but must stay out of analytics; rejection of a wrong value-row subject citation.
- **Acceptance criteria (deterministic, offline):** feed a **hand-written** V10-shaped response for the Northstar fixture — 12 financial observations citing `E029`–`E032`/`E028`/`E027`/`E025`, plus delivery Q3 and its 95 % target — through `GenerateInsightsJob` + `MetricCollector` + `DocumentAnalysisComposer`, and assert: ≥ 13 eligible observations; 4 three-point time-series charts; ≥ 1 `actual_vs_target`; the operating-profit reconciliation present; zero evidence rows without all four roles verified.
- **Positive tests:** duplicate observations for the same identity collapse to one; a `≤` ceiling keeps its operator; mixed-unit operands refuse to share a chart.
- **Negative tests:** an observation citing a value row for its subject is still rejected; a target cited to the actual column is rejected; an uncited chart point produces nothing.
- **Dependencies:** none for the offline acceptance test. **One authorized paid run** is required afterwards to measure whether the model complies, and at what token cost.
- **Complexity:** medium (low in code, medium in contract design and evaluation).
- **Risk / benefit:** highest-value change in this backlog — it is the difference between 1 and ~13 verified observations on this document, and it unlocks 4 charts, ≥ 1 comparison and the reconciliation **with no verifier change**. Risk: output tokens roughly 2–3×; duplicate/conflicting observations; the model may not comply, which the offline test cannot tell you.

---

### P3 — Correct diagnostic semantics (revision proof, gold scorer) and de-duplicate integrity post-processing

- **Objective:** stop the system explaining itself incorrectly, and remove the duplication that caused it.
- **Files:** `app/Services/Intelligence/MeasurementIntegrity.php` (:41-43, :63-64, :96-104); `app/Http/Resources/KpiResource.php` (:30-54); `app/Services/Intelligence/MetricCollector.php` (:150-153, :276-292); `tests/Support/NorthstarGoldScorer.php` (:37-56); `tests/Feature/NorthstarV9EvaluationTest.php` (:416-426, the fake candidate).
- **Exact behaviour to change:** three revision states (`revision_proof_missing` / `revision_matches` / `revision_conflict`), with `source_revision_mismatch` emitted only for a conflict; one shared post-assessment helper that appends `period_unavailable` / `semantic_identity_conflict` and de-duplicates reasons, called by both `MetricCollector::observe()` and `KpiResource`; scorer reads `identity.period` (top-level as fallback); the harness fake conforms exactly to the V9/V10 wire schema.
- **Must remain unchanged:** `analyticalEligible` for every candidate in the live artifact (1 eligible, 6 ineligible); all existing rejection codes.
- **Acceptance criteria:** re-scoring `live-20261021-135636-3d989a1dc905.json` with the corrected scorer yields the 7 non-zero matches the manual review established, not 0; no rejected candidate reports `source_revision_mismatch`; `MetricCollector` and `KpiResource` produce identical `reasons` for the same row.
- **Positive/negative tests:** a genuinely stale evidence row still reports a conflict; a missing-proof row reports `revision_proof_missing` and stays ineligible; a **new** `NorthstarGoldScorerTest` scoring the archived live payload (this is the missing regression that let R-08 survive).
- **Dependencies:** none. **Complexity:** low.
- **Risk / benefit:** low risk, high leverage — without it, every future evaluation of P1/P2 is measured with a broken ruler.

---

### P4 — Add the header-category (transposed, caption-dated) table orientation

- **Objective:** make "X by category for period P" tables verifiable.
- **Files:** `app/Services/Intelligence/PdfTabularCellVerifier.php` (`verify()` :57-101, `precedingCaption()` :128-141).
- **Exact behaviour to change:** when no column header matches the claimed period, attempt a second orientation: the preceding caption span must name exactly one period matching the claim; the cited header row supplies the category name at the cited column index, which becomes the subject proof; the value row need not carry a leading label. New rejection code `category_column_unverified`.
- **Must remain unchanged:** the row-label orientation and every verdict it produces today; `period_column_ambiguous` for a table with neither orientation.
- **Acceptance criteria:** `E040`/`E039` accepts `17.0` for Logistics Q3 2026 and `E037`/`E036` accepts `21.0` for North Q3 2026 (both `period_column_ambiguous` today, reproduced); with four such observations the composition chart appears via `ChartCandidateBuilder::labelCompositions()`.
- **Negative tests:** a caption naming two periods; two tables under one caption; a header mixing periods and categories; a claimed category absent from the header.
- **Dependencies:** P1 (shared column splitting). Pairs with P2 (the model must actually emit these observations). **Complexity:** medium.
- **Risk / benefit:** unlocks a whole common table class; risk is caption→table mis-association.

---

### P5 — Use the full verified table context for concept proof, and remove the fixture-shaped lexical rules

- **Objective:** stop discarding a column header the verifier already reconstructed; make the concept rule generic.
- **Files:** `app/Services/Intelligence/PdfTabularCellVerifier.php` (:85-101).
- **Exact behaviour to change:** match concept words against the union of {row label, preceding caption, the matched column header from `$columns` including its merged continuation}, all of which are already verified parts of this table. Replace `\btotal\s+proposed\b` (:99) with the structural condition "the cited row is a total row of this table". Replace the `['quarterly','rate','received','cases']` stoplist (:89) with a categorical rule: drop period words and the words of the claimed unit.
- **Must remain unchanged:** `concept_row_mismatch` for a row whose concept appears nowhere in this table's verified context; `kpi:1`–`kpi:6` verdicts.
- **Acceptance criteria:** `kpi:7` (`E077`/`E072`, concept `proposed service-recovery funding`) passes the concept check; it still carries `period_unavailable` and stays off every chart.
- **Negative tests:** an unrelated total under a nearby caption; a wrapped header that does not belong to the cited table; a proposed total relabelled `actual` still rejected by `ValueBasisEvidence`.
- **Dependencies:** none (P4 touches the same method — sequence them). **Complexity:** low-medium.

---

### P6 — Bounded document-local metric-definition binding

- **Objective:** let an explicit definition elsewhere in the document establish a unit.
- **Files:** `app/Services/Intelligence/NormalKpiGrounder.php` (`governs()` :241-259, the field-role loop :74-100, the `sources[]` assembly :130-160).
- **Exact behaviour to change:** a new, separately-named relationship (not a relaxation of `governs()`): accept a cross-page `table_row`/`list_item` as the `unit` source only when its leading cell matches the value row's leading cell under the existing singularisation rule, exactly one such span exists document-wide, it is not itself a value row for the metric, and the source revision matches. Record it in `sources[]` with role `unit`.
- **Must remain unchanged:** `governs()` for all other roles; the requirement that the subject role still be independently proved — **candidate 6 must still be rejected** on its invalid `"cases received"` subject citation.
- **Acceptance criteria:** with a corrected subject citation, complaints Q3 2026 = 133 `cases` is accepted citing `E049`/`E046`/`E114`/`E043`; with the original `E049` subject it is still rejected.
- **Negative tests:** two glossary rows for the same metric → refuse; a definition for a different population → refuse; a stale revision → refuse.
- **Dependencies:** P2 (needs a valid subject citation to be useful). **Complexity:** medium-high. Lowest value-per-risk in this list — it recovers one metric.

---

### P7 — Decide the bare-quarter period inference (R-03b)

- **Objective:** either enable operational time series or state that they are unsupported.
- **Files:** `app/Services/Intelligence/NormalKpiGrounder.php` (`quarterPageContext()` :278-307).
- **Exact behaviour to change (option i):** a page header establishing `Q<n> <year>` also establishes that **year** for sibling bare-quarter columns of the *same* verified table, provided the existing conflicting-year scan finds no other explicit year before the cited row and the header's quarter sequence is consistent. Record the inference in `sources[]` so it is auditable.
- **Must remain unchanged:** the conflicting-year refusal; the existing Q3 behaviour; refusal when a second year appears anywhere on the page before the row.
- **Acceptance criteria:** delivery Q1/Q2 2026 accepted from `E048`/`E046`, producing a 3-point delivery series; a synthetic page with `Q1` columns under two different years is refused.
- **Dependencies:** P2. **Complexity:** medium. **Explicit product decision required** — this is an inference, not a proof, and someone must sign it off.

---

### P8 — Remove or relabel the ungrounded model chart channel

- **Objective:** stop shipping unverified model numbers through a channel labelled "historical".
- **Files:** `app/Jobs/GenerateInsightsJob.php` (:231-270); `app/Http/Resources/ChartResource.php`; `CA/src/components/workspace/UnifiedCharts.tsx` (:116-121, `originLabel`), `CA/src/components/chartLayout.ts` (:167-177).
- **Exact behaviour to change:** preferred — drop `charts` from the requested schema (part of P2) and stop persisting insights-authored `DocumentChart` rows. Transitional — stamp `source_metadata.origin = 'model_unverified'`, give the frontend a distinct origin and a truthful message, and exclude them from CSV/Word export until verified.
- **Must remain unchanged:** `AnalyzeEmbeddedVisualsJob`-sourced `document_visual` charts and their image/verification flow; genuinely historical rows (pre-existing `source_metadata = null` from older runs) must keep an honest "origin unavailable" message — they are a different case.
- **Acceptance criteria:** no `document_charts` row from the insights path after the change (or all such rows carry the explicit origin); the frontend never describes a current-run model chart as historical.
- **Dependencies:** **ship after P2**, or the Northstar user loses their only visible chart data. **Complexity:** low-medium.

---

### P9 — Generalise `pageHeaderSubject` from "first span" to "unique candidate"

- **Objective:** stop entity-scope proof depending on page layout accident.
- **Files:** `app/Services/Intelligence/NormalKpiGrounder.php` (:262-275).
- **Exact behaviour to change:** accept any same-page span preceding the value span, typed `heading`/`section`/`table_row`, that is the **only** span on that page containing the claimed scope.
- **Acceptance criteria:** `E025 → E029` still true; a synthetic page beginning with `Page 3 of 12` followed by the entity banner now proves the subject (impossible today); a page naming two entities is refused.
- **Dependencies:** none. **Complexity:** low.

---

### P10 — Consolidate the two field-verification implementations (deferred)

- **Objective:** one field-role verification contract for both routes.
- **Files:** `NormalKpiGrounder`, `IncrementalMetricFieldVerifier`, `SourceProvenanceReader`, `MeasurementIntegrity` (:84-94).
- **Rationale for deferral:** it is genuine duplicated responsibility and a real maintenance cost, but it caused none of the Northstar failures, and doing it before P1–P3 would churn the exact code those changes touch. **Complexity:** high. Do not start until P1–P3 have landed and been measured.

---

## Part 5 — Proposed target architecture (smallest sufficient change)

No new layers, no new services, no rewrite. Four edits to the existing design.

### 5.1 The one structural idea

Today the model is asked for **two kinds of thing**: cited observations (verifiable) and chart JSON (unverifiable). The deterministic builders can only use the first, so the second is where most of the document's numbers end up. The target architecture asks the model for **exactly one kind of thing — the independently cited observation — and lets deterministic code own everything derived from it.**

### 5.2 Before and after

**Before (what Northstar did)**

```
model ─┬─ 7 KPIs            → grounder → 1 accepted ──→ MetricCollector → 1 eligible obs
       │                                  6 rejected ──→ ineligible obs (UI limitation text)
       ├─ 3 charts (10 pts) → document_charts (NO grounding) ─────────────→ UI "legacy chart", data table
       └─ 5 insights + 6 trendValue strings ─────────────────────────────→ prose only

ChartCandidateBuilder(1 eligible) → insufficient_points → 0 charts
AnalyticalComparisonBuilder(1)    → 0 comparisons
```

**After**

```
model ── ~25-30 cited observations, one per (concept, scope, period, basis)
          each: value + unit + identity + source_context{value,unit,period,subject}
          incl. target/ceiling operands (value_basis=target, operator preserved)
          NO chart JSON
   └─ GenerateInsightsJob: de-duplicate on (concept, scope, period, value_basis)
        └─ NormalKpiGrounder (unchanged logic, extended coverage)
             ├─ PdfTabularCellVerifier: row-label orientation (today)
             │                        + header-category orientation (P4)
             │                        + all delimiters isTableRow accepts (P1)
             │                        + concept proof over the whole verified table context (P5)
             ├─ subject: unique same-page entity header (P9)
             ├─ period: column header, or bounded same-table year inference (P7, audited)
             └─ unit:  cited span, or unique document-local definition (P6, audited)
                  └─ document_evidence(kind=metric) ── one row per observation, roles[] + exact cell offsets
                       └─ MetricCollector → MeasurementIntegrity (single shared post-assessment, P3)
                            └─ analyticalEligible observations
                                 ├─ ChartCandidateBuilder   (unchanged) → time series, categorical, composition
                                 ├─ AnalyticalComparisonBuilder (unchanged) → actual-vs-target, period change, reconciliation
                                 └─ DocumentAnalysisComposer → analysis{} → API → UI
document visuals ── AnalyzeEmbeddedVisualsJob → document_charts(source_metadata.origin=document_visual) → ChartResource
```

### 5.3 How a source fact becomes a verifiable analytical observation

For `Revenue, Q1 2026, 42.0 KES million, Northstar Distribution Ltd` — every step below was executed during this audit and passes:

1. **Extraction** places the row at offsets 2948–2970 and the banner at 2739–2810.
2. **Segmentation** types them `table_row` and assigns `E029`, `E025`.
3. **The model** emits one observation citing `value=E029, period=E028, unit=E027, subject=E025`.
4. **Cell proof** resolves column `Q1 2026` → cell `42.0` at offsets 2962–2966 (verified: ACCEPTED).
5. **Role proof** — period by literal match in `E028`; unit by `(KES million)` in `E027`; subject because `E025` uniquely names the entity and precedes the row (verified: `pageHeaderSubject = true`).
6. **Basis proof** — `ValueBasisEvidence` finds no contradicting keyword for `actual`.
7. **Persistence** — one `document_evidence` row, `source_fields_verified = [value, unit, period, subject]`, exact cell offsets, source revision.
8. **Observation** — `MeasurementIntegrity` → `analyticalEligible = true`; `groupKey()` = revenue concept + KES currency family + actual basis.
9. **Analytics** — three such observations (Q1/Q2/Q3) share a `groupKey` → one time series; revenue + costs + profit in one period → reconciliation; actual + target in one group → `actual_vs_target`.
10. **Presentation** — `analysis.visualAnalysis.charts` with per-point `sourceIds`; the UI resolves each point to its exact cited characters.

The only new capability required for steps 1–10 on the financial table is **step 3**. Steps 4–10 already work.

### 5.4 What must not change

The trust boundary stays exactly where it is: `analyticalEligible` is computed only from persisted field proof, and both builders re-filter on it independently. No builder may ever accept a model-asserted point. `MeasurementParser` remains the only place that normalises scale, and no currency is ever converted.

---

## Part 6 — Evaluation strategy

The central discipline: **never report a manually-confirmed model fact as a verified analytical output.** The V9 run was 7/7 correct and 1/7 verified; a single headline number hides exactly the problem this product has.

### 6.1 Metrics (report all seven, every run)

| Metric | Definition | V9 baseline | First-milestone target |
|---|---|---|---|
| Factual candidate precision | manually-correct candidates ÷ emitted | 7/7 = 100 % | ≥ 95 % (must not regress) |
| **Verified-observation coverage** | gold cases that become `analyticalEligible` observations ÷ gold cases | **1/29 = 3.4 %** | **≥ 13/29 ≈ 45 %** |
| Mission-anchor eligibility | `mission_anchor` gold cases eligible ÷ anchors | 1/17 = 5.9 % | ≥ 13/17 ≈ 76 % |
| Financial chart completeness | complete 3-point financial series ÷ 4 | 0/4 | 4/4 |
| Comparison correctness | deterministic comparisons produced, each arithmetically re-checked | 0 | ≥ 1 `actual_vs_target` + 1 reconciliation, 100 % correct |
| Evidence traceability | eligible observations whose every role resolves to exact stored characters ÷ eligible | 1/1 | 100 % (never below) |
| Correct rejection of unsupported evidence | injected bad citations rejected ÷ injected | not measured | 100 % |

### 6.2 False acceptance and false rejection — measure them properly

The V9 run establishes **neither rate**, and the prior report is right to say so. Both need deliberate construction:

- **False acceptance** needs an **injection suite** of hand-written responses whose citations are wrong in specific ways, run offline against the real fixture. Each case asserts a specific rejection code. Minimum set, each already shown in this audit to be either currently-correct or currently-broken:
  1. Value row cited as subject → `subject_source_mismatch` (currently correct — freeze it).
  2. **Space-delimited table, period of column A bound to the value of column C** → must reject (**currently accepts — R-02, the one proven hole**).
  3. Adjacent-column value → `value_column_mismatch` (covered by the existing recorded fixture's second candidate).
  4. Target value cited against an actual column → must reject.
  5. Glossary row for a different metric cited as unit → must reject.
  6. Two entity names on one page, one cited → must reject (R-09).
  7. Bare-quarter column under a conflicting year → must reject (R-03b).
- **False rejection** needs a **gold-citation suite**: for each of the 29 gold cases, the citation a careful human would choose. Any rejection of a hand-correct citation is a verifier false rejection by construction. This audit has already produced 13 such cases (R-01 table) and 3 known-blocked ones (R-05); completing the set to 29 is mechanical and needs no model call.

Both suites run **offline from frozen responses** — no paid calls, fully deterministic, suitable for CI.

### 6.3 Mandatory tooling fix first

P3 is a prerequisite for every number above. The current scorer reports 0/29 on a 7/7-correct run (R-08) and there is **no test for the scorer at all**. Add `NorthstarGoldScorerTest` scoring the archived `live-20261021-135636-…json` and asserting the manually-established match set. Without this, improvement cannot be measured.

### 6.4 Cost and latency

Record per run, as the V9 report already does well: model, prompt SHA, input/output tokens, cache read/write, stop reason, latency, estimated USD against published pricing, and the reservation ceiling. V9 baseline: 8,266 in / 5,196 out, 20.9 s, $0.003425. P2 will raise output tokens materially (a complete observation set for this document is roughly 25–30 candidates versus 7); gate the first paid run on an explicit ceiling as before, and report **verified observations per dollar** alongside raw cost — that is the ratio P2 is meant to move.

### 6.5 Generalisation beyond Northstar

Northstar is one document and a friendly one. Before claiming the architecture generalises, add at least one fixture per row:

| Fixture property | Which finding it would have caught |
|---|---|
| PDF whose extraction yields **space-aligned** tables, not tabs | **R-02 (false acceptance)** |
| **Two-column** label/value table | `count($cells) < 3` → `table_column_mismatch` (Part 2) |
| **Transposed / caption-dated** breakdown table | R-05 |
| Pages beginning with a **page number or footer**, entity header second | R-09 |
| A **non-quarterly** reporting basis (months, halves, fiscal years) | `quarterPageContext` is quarter-only |
| A **non-KES, non-percent** currency and a document mixing two currencies | `MeasurementParser` family/compatibility rules |
| A **DOCX** table | no cell verification exists for DOCX (`NormalKpiGrounder.php:38-41`) |
| A document with **no** usable metrics | that the product degrades honestly rather than emptily |

Each fixture needs only its extracted text plus a hand-written response; none needs a paid call.

---

## Part 7 — Codex implementation handoff

### Ordering rules

- **P3 first.** It is low risk, and until it lands every measurement of the others is wrong.
- **P1 and P2 are independent** and are the two changes that matter. P1 protects correctness; P2 produces output. Run them in parallel if two workstreams exist; if one, **P3 → P1 → P2**, because P1 changes the verifier that P2's acceptance test depends on.
- **P8 must come after P2**, or the product visibly loses its only chart data.
- **P6, P7 and P10 are deliberately last.** P6 recovers one metric at high risk. P7 needs a product decision about an inference. P10 is a refactor that would churn P1–P3's code.

### Execution plan

**Stage 0 — measurement integrity (P3). Validate alone.**
Three revision states in `MeasurementIntegrity`; one shared post-assessment helper used by both `MetricCollector` and `KpiResource`; scorer reads `identity.period`; harness fake conforms to the wire schema; **new** `NorthstarGoldScorerTest` over the archived live payload. Gate: re-scoring the V9 artifact reproduces the manual 7-match result; no rejected candidate reports `source_revision_mismatch`; `MetricCollector` and `KpiResource` agree on `reasons`; full suite green.

**Stage 1 — close the false-acceptance hole (P1). Validate alone.**
Shared delimiter/column splitting; `verify()` never returns `null` for a `table_row`. Gate: the R-02 claim is rejected; all 13 reproduction verdicts from R-01/R-05 unchanged; injection cases 1–3 from §6.2 pass; report the count of newly-rejected rows on whatever corpus is available.

**Stage 2 — the first milestone (P2). This is the demonstration.** See below.

**Stage 3 — table coverage (P4 + P5 together).** Same method in the same file; one review. Gate: `kpi:7` passes its concept check (still periodless, still off every chart); the four cost-function and three region observations verify; a composition chart appears; every negative case in P4/P5 rejects.

**Stage 4 — layout robustness (P9), then the trust channel (P8).** P9 is small and independent. P8 only after Stage 2 has produced deterministic charts.

**Stage 5 — judgement calls, each validated separately (P7, then P6).** P7 needs an explicit product sign-off on the year inference. P6 is the highest-risk, lowest-yield change in the backlog; do it last or not at all this cycle.

**Stage 6 — deferred (P10).** Only after Stages 0–3 are measured.

### First milestone — smallest change with measurable improvement

**Scope:** Stage 0 (P3) + Stage 2 (P2), measured offline with **zero paid calls**.

**Deliverable:** a V10 output contract plus a hand-written V10-shaped Northstar response driven through the **unmodified** production grounding, persistence, collector, chart and comparison code.

**Pass criteria, all offline and deterministic:**

| Measure | V9 baseline | Milestone |
|---|---|---|
| `analyticalEligible` observations | 1 | ≥ 13 |
| Verified-observation coverage (of 29 gold) | 3.4 % | ≥ 45 % |
| Complete 3-point financial series | 0 | 4 |
| Deterministic comparisons | 0 | ≥ 2 (one `actual_vs_target`, one reconciliation), each arithmetically re-checked |
| Evidence traceability of eligible observations | 100 % | 100 % |
| Injected bad-citation rejections | not measured | 100 % |
| Verifier changes required | — | **none** |

That last row is the point of the milestone: it demonstrates that the verification architecture was never the blocker, using the existing verifier untouched. This audit has already proven the inputs (R-01), so the milestone is an engineering exercise, not a research one.

**Only then** authorise one paid run against V10 to measure whether the model complies with the contract, and at what token cost. Until that run, report the milestone as *"the pipeline can produce complete trusted analytics from a correctly-shaped response"* — never as *"the pipeline produces complete trusted analytics."* Conflating those two is the mistake this audit exists to prevent.

---

## Appendix A — Reproductions performed in this audit

All offline, no model calls, no repository modification. Scripts lived in the session scratchpad; Part 6 specifies the equivalent permanent tests.

| # | What | How | Result |
|---|---|---|---|
| A1 | Extraction fidelity | `DocumentTextExtractor::extractPdfText()` on the fixture | text SHA-256 `a4722cf…fa8da` — **identical to the live run** |
| A2 | Span segmentation | `SourceSpanBuilder::build($text, 1)` | **126 spans**, identical count; `E025`–`E057`, `E114` inspected directly |
| A3 | Cell verification, 13 claims | real `PdfTabularCellVerifier::verify()` against the real span set | prior quarters, targets, ceiling, shipments, employees **all ACCEPTED**; both breakdown tables `period_column_ambiguous` |
| A4 | Period-role resolution | reflection on `NormalKpiGrounder::containsWords` / `quarterPageContext` | financial Q1/Q2 **OK**; delivery & complaints Q1/Q2 **`period_source_mismatch`**; delivery Q3 + target OK via `E043` |
| A5 | Subject-role resolution | reflection on `pageHeaderSubject` / `governs` | `E025→E029` true, `E043→E048` true, `E027→E029` governs-only, `E029→E029` false |
| A6 | Cross-page glossary | `governs(E114 → E049)` | **false** (page check) |
| A7 | **Space-delimited false acceptance** | synthetic table through real `SourceSpanBuilder` + real grounder helpers | `verify() = NULL`; `containsValue`, `containsConcept`, `containsWords`, `governs`, `pageHeaderSubject` **all true** for a wrong period→value binding |
| A8 | Existing regressions | `./vendor/bin/phpunit --testsuite Unit --filter "Chart\|Metric\|Measurement\|Grounder\|Tabular\|Comparison"` | **52 passed, 459 assertions** — these defects are invisible to the current suite |
| A9 | Scorer defect | V9 wire schema vs `recorded-…json` / `live-…json` | recorded `exact_gold_matches = 1` (fake injects top-level `period`), live `= 0` (real schema has none) |

## Appendix B — Constraints observed

- No application source file, prompt, seeder, migration or schema was modified. No file was created except this report.
- The working tree was preserved exactly as found; no `git add`, commit, push, merge or deploy.
- No paid AI API call. No provider credential was read or echoed. No production or customer data touched.
- No dependency installed. Only the already-present PostgreSQL test database was touched, read-only, by the existing offline unit tests.
- Restricted evaluation data (the diagnostics directory, mode 0700) was read but is not reproduced here beyond the rejection codes, span texts and token/cost figures the prior report already publishes.
