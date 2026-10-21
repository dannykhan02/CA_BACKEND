# DocIntel V10 offline recovery handoff

21 October 2026 · branch `feat/docintel-v10-evidence-integrity` · no paid inference, migration, deploy, push or production-data write.

## Evidence and baseline

The independent audit and frozen V9 report were checked against the current source before edits. The original PDF still extracts to SHA-256 `a4722cfdb3795de38553953d1c12521053a5368232bf3a0cbcd4a952712fa8da` and 126 spans. The ignored, mode-0600 live diagnostic was left unchanged (SHA-256 `bab23acf80677428da92a0796edbe9237a5df199a54110b32a45e3483195e7cd`). A committed sanitized fixture retains only the seven candidate facts, identities, rejection flags and value-row text needed for scorer regression; it contains no provider envelope, credentials or restricted diagnostics.

The path remains PDF extraction → source spans → provider response → KPI identity resolution → `NormalKpiGrounder` → metric evidence → `MetricCollector` → `MeasurementIntegrity` → deterministic chart/comparison builders → API resources. Neither the extractor nor the frontend was changed.

## Confirmed defects and corrections

1. `NorthstarGoldScorer` read a nonexistent raw `candidate.period`. It now reads `candidate.identity.period` and distinguishes the financial operating-cost row from the separate cost-total row using the resolved value-row text. Strict exact matches rise from 0 to **4**, while the historical manual factual review stays **7/7**. The three remaining candidates differ in label, scope or source location and remain for manual review. The recorded harness now omits its fabricated top-level period. Scoring the sanitized recording and the retained live artifact yields the same match indexes.
2. Absent evidence was described as `source_revision_mismatch` by the KPI API. Missing revision proof now reports `revision_proof_missing`; matching proof remains eligible and a genuinely conflicting hash still reports `source_revision_mismatch`. The collector and KPI resource use one shared period/identity post-assessment gate.
3. The non-tabular table bypass was reproduced through the real database-backed `NormalKpiGrounder::record()` path **before** the fix. A false FY2023 = 51.0 claim persisted all four verified roles and became analytically eligible, even though 51.0 occupied FY2025. After the fix, every `table_row` must yield a structural cell proof or a rejection. Tab, pipe and unambiguous multi-space rows retain exact cell offsets. Invalid period/value/operator bindings, ambiguous headers, inconsistent widths and unsplittable rows fail closed. Genuine prose still uses its separate path.
4. V9's one-observation-per-metric plus model chart JSON contract prevented complete trusted series. Inactive V10 asks for separately cited observations for meaningful periods and actual/target operands. It preserves original units, scale, scope, basis and printed comparison operators; periodless facts remain periodless. The parser requires complete V10 field-role objects and accepts a response without chart JSON. V9 prompt and stored-document behavior remain available. V10 starts at 14,000 output tokens, can retry at 20,000, and asks for at most 30 prioritized observations.
5. The comparison builder previously rejected a verified actual delivery cell because its cited **whole header** also named an adjacent target column. The grounder now persists the selected column label as `source_column_basis`; the collector carries that verified cell context to comparisons. Identical duplicate observations are collapsed deterministically. Contradictory source values are withheld from chart and comparison calculations instead of choosing a higher-confidence one.
6. New V9 insights-authored charts are tagged `source_metadata.origin = model_unverified`. API serialization distinguishes them from `document_visual` and historical null provenance, gives a truthful limitation message and disables CSV/PNG/Word export-support flags for unsupported rows. A V10 job discards model-authored chart JSON even when a mocked client bypasses parser validation. Genuine extracted document visuals and historical rows are retained.

## Northstar results

| Measure | Frozen V9 live model | Hand-cited V10 offline input through production backend |
|---|---:|---:|
| Source-correct emitted facts | 7/7 by manual source review | 14/14 by construction and cell verification; **not model performance** |
| Independently eligible observations | 1 | 14 (12 financial + delivery actual/target) |
| Complete financial series | 0/4 | 4/4, each Q1/Q2/Q3 |
| Trusted generated charts | 0 | 4 |
| Deterministic comparisons | 0 | 8: four Q2→Q3 changes, three quarterly profit reconciliations, one delivery actual vs target |
| Exact cell/source traceability | One eligible delivery observation | Every eligible observation has role evidence; all 12 financial chart points have exact cited cell offsets; the composite API maps all 14 source IDs |
| Invalid associations | Four financial subject citations correctly rejected; the synthetic delimiter bypass was absent from this PDF | Seven wrong Northstar field associations rejected; synthetic wrong-period cell now rejected after a reproduced false acceptance |

The four period changes are revenue +5.0 and operating costs +5.0 KES million, operating profit 0, and operating margin −2.1 percentage points. Q3 delivery actual 87% is 8 percentage points below its independently cited 95% target. The three source-verified revenue-minus-costs checks match reported operating profit. No model trend string or model chart point enters the generated series.

Available recorded V9 and original Northstar financial fixtures show no decrease in valid cell acceptance: the recorded V9 accepted observation remains accepted, and all 12 financial source cells ground. A wider production-document acceptance census was not available offline; newly rejected non-tab table rows without structural alignment are intentional safety rejections, not established factual regressions.

## Verification

- Initial baseline slice: 35 unit tests, 260 assertions passed.
- Final broad no-migration unit slice: **622 tests, 2,956 assertions passed** across 76 files.
- Final broad no-migration feature slice: **44 tests, 700 assertions passed** across nine files.
- Recorded V9 evaluation harness: **5 tests, 224 assertions passed** in the isolated local PostgreSQL cluster with HTTP fakes and rollback transactions.
- Table-safety suite: **5 tests, 79 assertions passed**, including 12 valid Northstar cells, stale revision and prose controls.
- V10 offline suite: **5 tests, 228 assertions passed**, including the fake provider boundary, 14-observation job path, seven invalid field associations and chart provenance controls.
- Final unit run reported five PHP 8.5 `imagedestroy()` deprecations. The full PHPUnit suite was not run because many existing test files use migration or truncation traits and this assignment prohibits executing migrations or destructive database operations without separate authorization. No schema change was introduced.

All database-backed runs used the pre-existing isolated PostgreSQL 18 cluster on port 55449. The newly added feature tests and the recorded V9 harness use rollback transactions. The isolated schema existed before this work; no migration was executed.

## V10 live-readiness and cost

The backend is ready for a **controlled** V10 live-model evaluation in a disposable local database: the inactive prompt can be selected, a faked provider response passes the real parser at a 14,000-token request cap, and the normal job builds verified analytics. This proves offline capability only. It does not show that a model will emit the required observations or choose correct citations.

The hand-written 14-observation JSON is 5,529 bytes. The frozen V9 call used 8,266 input and 5,196 output tokens for seven candidates plus chart and narrative material. V10 may spend more output tokens, including model thinking; the actual usage is unknown until a paid run. The current Haiku 5.5 short-prompt prices are [$0.10 per million input and $0.50 per million output tokens](https://platform.claude.com/docs/en/models/haiku-5-5/overview). At roughly 9,000 input tokens, a 14,000-output-token first attempt is approximately **$0.008** at its cap. If it truncates and both the 14,000-token attempt and a 20,000-token retry are billed, the approximate combined cap cost is **$0.019**. The current model supports far more output than these application caps, but its thinking tokens count toward `max_tokens`; complete JSON is required and a truncated response is not accepted. These are estimates, not charged usage or a billing guard.

Smallest next experiment, **only after explicit paid authorization**: one Northstar V10 live response in a disposable local database with a process-scoped spend cap, retained raw diagnostics under the ignored restricted directory, and comparison against this offline 14-observation suite. Check coverage, citation-role correctness, eligibility, token usage, truncation and model-chart compliance separately. Do not activate V10 in production on the strength of the hand-written fixture.

## API and frontend coordination

The `DocumentResource.charts[].sourceOrigin` contract now adds `model_unverified`; `document_visual` and historical `null` are unchanged. New model chart rows have `renderEligible=false`, `valuesVerified=false`, a truthful `unavailabilityReason`, and `exportSupport.csv/png/word=false`. `analysis.visualAnalysis.charts` remains the distinct verified `generated` channel. The current frontend types in `CA/src/types.ts` admit only `document_visual|null`, and `CA/src/components/chartLayout.ts` plus `CA/src/pages/reportBuilder.tsx` still label every other row “historical/legacy”. Those surfaces should explicitly recognize `model_unverified` and honor the Word export flag in coordinated frontend work. Existing charts remain readable; no old rows were rewritten or deleted by a migration. The backend does not claim unsupported model values are verified.

## Remaining work, ranked

1. **Correctness:** add a governed live-evaluation spend guard before a paid V10 experiment; measure model citation and observation reliability, especially entity headers and actual/target columns. No live V10 performance is known.
2. **Product trust:** coordinate frontend labels and Word export handling for `model_unverified`. Historical unknown-provenance rows cannot be retroactively classified without evidence.
3. **Coverage:** support transposed or caption-dated tables and cross-page definitions with bounded, unambiguous relationships. Current rejection is safe but loses some valid facts.
4. **Coverage:** decide bare-quarter year inference separately; a Q1/Q2 column under a Q3 page header is not currently enough to invent a full earlier period.
5. **Later:** assess the proposal-total concept/header issue, non-first entity page banners and Intelligence V2 readiness on their own regressions. Intelligence V2 remains disabled.
