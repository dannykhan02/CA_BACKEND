# DocIntel — Intelligence V2 / Brief activation readiness

- **Date:** 21 October 2026
- **Scope:** can the dormant `brief` payload reliably supply adaptive section ordering and attention metadata, and what are the risks of activating it.
- **Method:** code reading plus the repository's own offline test suites, and three read-only probes written to a session scratchpad (not added to the repository). **No paid inference, no migrations, no flag changed, no deployment.** `AnthropicClient` was bound to throw in every probe, so a provider call would have failed the run rather than been made.
- **Repository state:** `CA_BACKEND` HEAD `8c8991b`.
- **Verdict:** **the ordering and attention metadata are ready; the figure channel is not.** Activation is recommended behind a per-workspace gate, for ordering and attention only, with two defects fixed first.

---

## 1. Existing coverage, run offline

Every V2 suite in the repository passes on HEAD:

| Suite | Tests | Assertions |
|---|---|---|
| `Unit/IntelligenceBriefAssemblerTest` | 5 | 53 |
| `Unit/IntelligenceBriefVerifierTest` | 13 | 85 |
| `Unit/IntelligenceMaterialityTest` | 17 | 80 |
| `Unit/IntelligenceHistoricalAttentionTest` | 5 | 14 |
| `Unit/IntelligenceCoverageAndAbsenceTest` | 3 | 27 |
| `Unit/IntelligenceCoverageStagesTest` | 5 | 30 |
| `Feature/IntelligenceBriefApiWiringTest` | 6 | 707 |
| `Feature/IntelligenceBriefEvidencePathTest` | 1 | 10 |
| `Feature/IntelligenceBriefLegacyTest` | 1 | 13 |
| `Feature/IntelligenceBriefTakeawayRoutingTest` | 3 | 12 |
| `Feature/IntelligenceBriefNarrativeGuaranteesTest` | 23 | 251 |
| `Feature/IntelligenceMaterialityEvidenceBudgetTest` | 1 | 6 |
| `Feature/DocumentIntelligenceAnalysisTest` | 51 | 419 |

`IntelligenceBriefApiWiringTest::test_one_get_projects_stage_a_once_and_preserves_existing_response_fields` is the important one: with `intelligence_v2.enabled` already true, toggling `intelligence_v2.brief.enabled` adds the `brief` key and leaves **every other response field byte-identical** (modulo timestamps). It also pins Stage A to exactly one projection per GET, and the suite proves that no read path constructs a provider client, dispatches synthesis or writes a checkpoint.

## 2. Two flags, not one — and only one of them is additive

`BriefReadService::forDocument()` returns null unless **both** `intelligence_v2.enabled` and `intelligence_v2.brief.enabled` are true. `DOCINTEL_V2_BRIEF` already defaults to `true`, so the flag that actually gates everything is `DOCINTEL_INTELLIGENCE_V2` — and that one is **not** read-only. It is consulted in five places beyond the Brief:

| Consumer | What changes when it is on |
|---|---|
| `DocumentAnalysisComposer` | adds `analysis.tier1` and `analysis.attention` |
| `ImportantFindingsBuilder::build()` | takes an entirely different code path (`buildV2`) when a materiality assignment is supplied |
| `TakeawayBuilder::amount()` | formats numbers through `ValueFormatter` instead of its own `number_format` |
| `EvidenceBudget::forDocument()` | **re-orders the evidence sent to the synthesis prompt** by materiality tier and score |
| `BriefReadService` | serves the Brief |

The fourth is the one to be careful about. `EvidenceBudget` is on the **processing** path, not the read path: with V2 on it runs `MetricCollector`, `ChartCandidateBuilder`, `MaterialityRecords` and `MaterialityScorer` to decide which evidence fits the 16,000-byte synthesis budget. So turning `DOCINTEL_INTELLIGENCE_V2` on changes what the model sees for every **newly processed** document, which can change its output. `IntelligenceMaterialityEvidenceBudgetTest` demonstrates the swap directly: the same document hands V1 an uncited metric and V2 a cited fact.

**Recommendation:** treat activation as two decisions. The read-path change (`tier1`, `attention`, `brief`) is safe and reversible. The processing-path change is a model-input change and belongs behind the existing `intelligence_v2.workspaces` allow-list — which is declared in `config/intelligence_v2.php` as `'workspaces' => []` and **is read nowhere in `app/`**. Wiring that array up is the smallest honest way to activate for one workspace at a time.

## 3. Measured cost of the read path

One authenticated `GET /api/documents/{id}/intelligence`, local Postgres, warm, timed in-process with `hrtime` and `DB::getQueryLog`:

| Evidence size | v2 off, brief off | v2 on, brief off | v2 on, brief on |
|---|---|---|---|
| 5 metric findings + 2 risks | 30.7 ms · 40 queries · 5.3 KB | 41.7 ms · 42 queries · 10.7 KB | 50.3 ms · 45 queries · 13.1 KB |
| 40 metric findings + 2 risks | 59.6 ms · 46 queries · 8.4 KB | 95.9 ms · 48 queries · 13.8 KB | 135.4 ms · 51 queries · 16.2 KB |

Reading these:

- **No N+1.** Five extra queries regardless of size; the cost is CPU in `MaterialityScorer` and `AttentionStateBuilder`, both of which run per record.
- **It scales with record count, and the endpoint is polled.** `+36 ms` for V2 and `+40 ms` more for the Brief at 40 records is acceptable; a 300-page pack with several hundred evidence rows is the case to measure before activation, because `DocumentDetailPage` polls this endpoint while a document is processing and `DocumentAnalysisComposer::compose()` recomputes everything on each poll. Nothing is cached between GETs.
- **Payload roughly doubles,** and most of the growth is not the Brief. `analysis.tier1` added 5.4 KB at *five* findings, because each item embeds the full `tierReasons` signal breakdown — `kind_class`, `severity`, `structural_prominence`, `boilerplate_penalty`, `tier_adjustment`, each with weight, contribution, and a `skipped`/`reason` pair. That is diagnostic detail shipped to every client on every poll. If the frontend only needs the order, `tierReasons` should be behind a debug parameter.

## 4. The ordering and attention metadata are ready

From a probed response (`intelligence_v2.enabled` and `brief.enabled` true, B2 off):

- `analysis.tier1` is an ordered list of `{sourceId, kind, forced, forcedRule, tierReasons, attention}`. For the probe document the two risks came back as `forced: true` with `forcedRule: critical_risk` and `high_risk`, in that order. **This is sufficient for section and item ordering**, and it is keyed on `sourceId`, which is what every existing frontend component already uses to open evidence.
- `analysis.attention` is `{state, needs_attention_count, watch_count, next_due, coverage, forced_overflow}`, with `state: 'needs_attention'` and `needs_attention_count: 2` for the probe. **Sufficient for the Attention section** in the presentation audit's Part D.
- `brief.blocks` arrive ordered, with `type`, `tier`, `cites`, `typed`, `attention`, `attribution`, `assertion`, `template_id`/`template_version`, and an `evidence[]` whose entries carry `record_id`, `source_id`, `span_id`, `chunk_id`, `start_offset`, `end_offset`, `quote`, `page` and a `highlight` object.

Two integration notes for whoever declares the frontend type:

1. **The envelope is camelCase and the blocks are snake_case.** `BriefReadService` renames its own keys (`templateVersion`, `fallbackReason`, `audit.contractVersion`…) but passes `blocks` through verbatim from `BriefAssembler`, so inside a block it is `template_id`, `absence_check`, `chart_id`, `ai_generated`, `start_offset`, `record_id`, `span_id`. A single convention cannot be assumed.
2. **`highlight.mode` is only ever `none` or `page_only` today.** The `start_offset`, `end_offset`, `needle`, `occurrence` and `occurrences` fields are present and always null in `BriefAssembler::evidence()`. No UI may claim an in-page position from this payload until those are populated and independently validated.

## 5. The figure channel is not ready — two measured defects

### V2-01 — `measure` blocks are currency-only, by construction

`KeyFigureSelector::select()` admits a record only when `typed.value.type === 'money'`, `unit_kind === 'currency'` and a non-empty `currency` string. Probed directly: a document whose only measures are `Headcount: 4120 employees` (parsed as `type: count`) and `On-time delivery: 87%` (parsed as `type: percent`) produces **zero** `measure` blocks — the Brief is `headline` + `coverage_note` and nothing else, while `StageASnapshot` reports both metric records present and correctly typed.

Consequence for the product: the flagship fixture's operational table — shipments, on-time deliveries, complaints, employees — contributes no figures to the Brief at all. **The Brief cannot be the source of figures for a view.** It can order them; the figures must keep coming from `analysis.analysisGroups` / `doc.kpis`, which already carry every unit kind with its verification labels.

Severity: **S3** (a documented scope limit, not an incorrect claim) — but it must be understood before anyone plans a Brief-driven figure section.

### V2-02 — a bare number inside a metric label silently drops its whole `measure` block

**Severity: S2 (verified evidence is lost, and the loss is invisible in the payload). Reproduced.**

`BriefVerifier::numbers()` extracts every quantity from the rendered block text, and `numbersGrounded()` requires each one to match a **typed value on a record**, not merely to appear in the cited quote. A metric label containing a standalone number puts a quantity into the text that has no typed counterpart, the check fails, and `BriefAssembler` discards the block in its final filter.

Probe: one money metric, `51.0 USD million`, period `FY2025`, cited quote `"<label>: 51.0 USD million (FY2025)"` — so the label's digits are inside the quote. Only the label varies:

| Metric label | Rendered block text | Result |
|---|---|---|
| `Revenue` | `Revenue: USD 51.0 million (FY2025)` | kept |
| `Total financing` | `Total financing: USD 51.0 million (FY2025)` | kept |
| `Q3 revenue` | `Q3 revenue: USD 51.0 million (FY2025)` | kept |
| `FY2025 revenue` | `FY2025 revenue: USD 51.0 million (FY2025)` | kept |
| `Tier 1 capital` | `Tier 1 capital: USD 51.0 million (FY2025)` | **dropped** — `numbers_grounded` |
| `Scope 1 emissions` | `Scope 1 emissions: USD 51.0 million (FY2025)` | **dropped** — `numbers_grounded` |
| `Scope 3 emissions` | … | **dropped** — `numbers_grounded` |
| `Segment 2 revenue` | … | **dropped** — `numbers_grounded` |
| `Revenue (note 12)` | … | **dropped** — `numbers_grounded` |
| `Top 5 customers revenue` | … | **dropped** — `numbers_grounded` |
| `Basel III capital` | … | **dropped** — `entities_grounded` |

`Q3` and `FY2025` survive because the extractor's `(?<![\p{L}\d])` lookbehind rejects a digit preceded by a letter, and four-digit integers are skipped as years. Everything else is a real label shape: *Tier 1 capital*, *Scope 1/2/3 emissions*, *Segment 2*, *(note 12)*, *Top 5 customers* are ordinary vocabulary in bank, sustainability and management reporting.

Two things make this worse than a missing figure:

1. **The loss is silent.** `BriefAssembler`'s filter discards the block without a counter; `ai_blocks_rejected` is hard-coded `0` and describes AI blocks only. Nothing in the payload lets a consumer distinguish "this document has no figures" from "its figures were dropped", which is precisely the distinction the presentation audit requires a coverage statement to make.
2. **It is the label, not the evidence.** The value is verified, the quote contains it, and the record is sound. A presentation rule is discarding verified evidence.

Suggested direction, for the backend owners to weigh — **not implemented here, since backend grounding and verification are out of scope for this change**: exclude the label span from `numbers()`' input, the way `dates`, `periods` and `durations` are already excised from it, so the check applies to the asserted quantity rather than to the subject's name. Whatever the fix, it needs a regression table of label shapes; the one above can serve.

## 6. One frontend wording hazard that activation would switch on

`CoverageStateBuilder` resolves `bounded` when telemetry is unknown (`$telemetryUnknown` at line 51), not `unavailable`. The probe document — which has no recorded coverage at all — therefore returns `attention.coverage.state: 'bounded'` with every counter `null` and nine `*_unknown` reasons.

`WorkspaceCoverage.STATE_COPY.bounded` in the frontend reads: *"Coverage was bounded by measured omissions or source selection; completeness was not established."* The second clause is true. The first asserts a measurement that did not happen. It is unreachable today because V2 is off; turning V2 on makes it the sentence most documents show. Either the copy must distinguish "bounded by measured omissions" from "unknown telemetry" using `coverage.reasons`, or the state must not be rendered as prose at all. This is a frontend fix and belongs with the coverage-line work in the presentation audit's Part G (N2).

## 7. Recommended activation sequence

1. **Fix V2-02** (and decide whether V2-01's currency-only scope is intended). Add the label-shape regression table.
2. **Fix the `bounded` wording** in `WorkspaceCoverage`, or gate the state behind `reasons`.
3. **Wire `intelligence_v2.workspaces`**, which is declared and unread, so the processing-path change can be taken one workspace at a time.
4. **Measure a real large document** — several hundred evidence rows — on the read path, and decide whether `tierReasons` ships to clients.
5. **Declare `brief` in `CA/src/types.ts`** and consume it for **ordering and attention only**, with the existing `analysis` ordering as the fallback when the key is absent. Figures keep coming from `analysisGroups` / `doc.kpis`.
6. **Leave `DOCINTEL_V2_BRIEF_NARRATIVE` off.** B2 is a paid provider call and its guarantees (`max_rejected_claims: 0`, fail-closed to B1) are already proven by `IntelligenceBriefNarrativeGuaranteesTest`; nothing in the presentation work needs it.

## Appendix — probes

Three read-only PHPUnit classes were written to the session scratchpad and run with `vendor/bin/phpunit <path>`, reusing `Tests\Concerns\BuildsIntelligenceFixtures`. They are **not** in the repository: each would need a permanent home and a stable fixture before it became a test, and §5's brief was to validate rather than to add coverage.

- `V2ReadinessProbe` — timings, query counts, payload sizes and the served `brief`/`tier1`/`attention` shapes, with V2 and the Brief toggled independently.
- `V2FigureProbe` — which metric records become `measure` blocks, with the `StageASnapshot` typed value printed beside each.
- `V2DropProbe` / `V2LabelProbe` — one metric at a time through `DeterministicTemplates::render()` and `BriefVerifier::verify()`, printing the rendered text, the stored quote and the failed checks. This is what produced the table in §5.

Section 5's table is worth re-creating as a repository test when V2-02 is fixed.
