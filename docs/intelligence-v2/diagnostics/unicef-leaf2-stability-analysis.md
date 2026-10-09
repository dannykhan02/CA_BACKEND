# UNICEF leaf 2: offline extraction stability analysis

**Scope and status.** This analyzes only pages 10–22 of one dense UNICEF source slice using the eight saved calls C1–C3, E1–E3, and T1–T2. Leaf 1, which contains some headline figures, is absent. No provider call or production mutation was made. The original `/tmp/unicef-ab-diagnostic` directory, its `analysis.json`, and `/tmp/unicef-ab-diagnostic.tar.gz` were verified present before analysis. The source file is 37,584 bytes with SHA-256 `5e9e9609210a288163288a1d64a9ffd5a153116d58f2ec201f6f76a0944ee023`. Branch commit: `8c02050914b1c9821c8cd7afe2bf7a2d70911dbc`.

## Methods and evaluability

**Identity.** The primary quantitative identity is the experiment's cited span IDs plus canonical numeric value(s), scale, currency, and percent notation. For a base collision within a call, normalized label, period, and unit disambiguate. Nonnumeric identities use kind, cited spans, and normalized value. No fuzzy matching is used. The stricter experiment sensitivity identity always adds kind, period, unit, metric type, and value basis for quantitative records. These are the same two rules used by the earlier experiment. The primary view yields 193 identities; the strict view yields 372. Label and metadata wording drift can still split a primary identity, particularly when the same amount occurs more than once in one span.

**Provenance before eligibility.** Fixed `asOf`: **2026-10-09T00:00:00+03:00**. All 607 saved accepted records passed the current `TypedEvidenceProjector` and `DateRoleResolver` without projection errors. This invokes `ProvenanceProjector` and its CR-015 `NumericEquivalentGrounding`. The current `KeyFigureSelector` was then run on each record as a singleton to evaluate its *eligibility predicate*. Reconstructed citations use the saved source-span text and IDs; no evidence was written to application tables. Per-record origin, assertion, typed value, and eligibility are in the JSON companion. Full-document top-six **selection** is not evaluable because leaf 1 and other document evidence are absent.

**Materiality, two required frames.** Exact **per-run** Stage A scores, tiers, and forced Tier 1 status are **NOT EVALUABLE**. Exact **union-set** scores, tiers, and forced status are likewise **NOT EVALUABLE**. `MaterialityScorer::assign()` depends on the full document's typed record population, synthesis-cited source IDs, chart comparability, derived risk/deadline status, entity rows, and full source-span context. The bundle has only this leaf and no synthesis or derived rows. Passing empty context would silently convert missing signals to zero and would not be the current production result. No substitute scorer or tuned config was used. Consequently, tier changes caused by a changed comparison population cannot be identified; none is misclassified as extraction loss. `importantFindings` eligibility and selection are also **NOT EVALUABLE** because they require the full assignments and synthesis already-shown set. “Major currency metric” as a production materiality/forced classification is **NOT EVALUABLE**; no arbitrary dollar threshold was invented.

## Confirmed stability

The table uses primary identities. “Stable” means present, or for the key-figure row **eligible**, in all eight calls. Obligations and deadlines count the stated kind in each call, even when the same numeric identity appears under another kind elsewhere. Empty tier rows reflect unavailable scorer inputs, not zero findings.

| Category | Stable 8/8 | Found ≥6/8 | Found ≤2/8 | Total | Stability |
|---|---:|---:|---:|---:|---:|
| All evidence | 17 | 44 | 108 | 193 | 8.8% |
| Quantitative evidence | 17 | 44 | 85 | 169 | 10.1% |
| Union Tier 1 | NE | NE | NE | NE | NE |
| Union Tier 2 | NE | NE | NE | NE | NE |
| Union Tier 3 | NE | NE | NE | NE | NE |
| Union Tier 4 | NE | NE | NE | NE | NE |
| Forced Tier 1 | NE | NE | NE | NE | NE |
| Key-figure eligible in at least one call | **1** | **9** | **24** | **52** | **1.9%** |
| Obligations as classified | 0 | 0 | 1 | 1 | 0% |
| Deadlines as classified | 0 | 0 | 1 | 1 | 0% |
| High/critical risks | 0 | 0 | 0 | 0 | N/A |
| Major currency metrics | NE | NE | NE | NE | NE |

Only **one** identity was key-figure eligible in all eight calls: the **$97.4 million Emergency Programme Fund allocation** at E187. Six ever-eligible identities were *present* in all eight, but five lost eligibility in some calls because projected origin or typed-value grounding changed. Nine identities had eligibility drift among calls in which they were present. Per-call eligible counts were **27, 19, 3** (C), **19, 26, 19** (E), and **19, 22** (T0). This is eligibility, not evidence that those figures would all appear in the Brief's capped top six.

Examples eligible in only one or two calls include the **$170.5 million** Learning and Skills, **$168.5 million** Protection from Violence and Exploitation, and **$111.3 million** Freedom from Poverty spending observations at E196. Several $10 million programme records are also rare under the experiment-compatible identity; their label variants can split the same underlying amount. Examples present in at least six calls include **over $120 billion** India water and sanitation investment (7 calls, eligible in 6), **$53.9 million** global/regional allocation (8 present, 6 eligible), and the $97.4 million EPF figure (8 present and eligible). The JSON companion lists every identity, call, projection, and span.

The only obligation-classified record is the first-48-hours emergency-response observation at E185: its numeric identity appeared in four calls, but was classified `obligation` only in T2 and `fact` in three. The 20-additional-countries observation at E171 appeared in four calls but was classified `deadline` only in C2 and `metric` in three. These are **classification instability**, not four independent omissions. No high/critical risk record appeared in the accepted outputs; that does not prove the source has none.

**Strict sensitivity.** Pairwise strict Jaccard is C **.191/.036/.104**, E **.146/.159/.073**, and T0 **.300**, with no identity shared across all eight. The primary view is less brittle and remains the main analysis; the strict view demonstrates that period, unit, and wording drift add substantial apparent instability.

## Structural location

These are mutually exclusive diagnostic source buckets based on the first cited span. `dense_multi_value` means a non-table span containing at least three numeric tokens. It is not a production materiality class. “Instability” here means found in fewer than eight calls.

| Structural source | Stable 8/8 | Found ≤2/8 | Total | Instability |
|---|---:|---:|---:|---:|
| `table_row` | 0 | 0 | 0 | N/A |
| Dense multi-value | 8 | 82 | 134 | 94.0% |
| Ordinary prose | 8 | 20 | 42 | 81.0% |
| List item | 1 | 0 | 5 | 80.0% |
| Heading/section | 0 | 6 | 12 | 100.0% |

**126/176 (71.6%)** unstable identities and **82/108 (75.9%)** identities found in at most two calls came from dense multi-value spans. The material candidate signal clusters there too: **48/51 (94.1%)** identities whose key-figure eligibility was not stable in all eight came from dense multi-value spans; all **24** eligible in at most two calls came from them. This is not merely table-row churn. The segmenter labeled 53 source spans `table_row`, but none was cited by an accepted record. Many of those spans are recurring report headers, page numbers, and photo credits; 49 have a numeric token. Their existence is not proof of 53 missing financial rows.

## Deterministic proxy and predictors

The saved source scan has 49 currency, 60 scaled-quantity, 35 percentage, 222 standalone-number, and 19 date/year hits (385 overlapping category hits; about 223 distinct source occurrences). Candidate coverage means **a span containing the candidate was cited**, not that its candidate claim was recovered. The all-call identity union is not ground truth.

| Call | Uncovered currency / scaled / percent hits | Missed ever-eligible identities | Missed identities whose every cited span was already covered |
|---|---:|---:|---:|
| C1 | 11 / 12 / 12 | 23 | 22 |
| C2 | 7 / 8 / 4 | 29 | 26 |
| C3 | 10 / 8 / 4 | 42 | 40 |
| E1 | 18 / 18 / 16 | 33 | 21 |
| E2 | 5 / 6 / 3 | 26 | 23 |
| E3 | 4 / 4 / 10 | 32 | 31 |
| T1 | 4 / 2 / 4 | 33 | 31 |
| T2 | 5 / 5 / 12 | 30 | 28 |

Across runs, **222/248 (89.5%)** absent ever-eligible identity/run pairs had every cited source span already touched by some other accepted record. Thus a span-touch coverage proxy would be blind to most of these union-relative misses. This count is a screening diagnostic, not true false-negative rate: the union can contain wording variants and is not a gold set. Candidate-level true positives, false positives, false negatives, precision, and recall are **NOT EVALUABLE** without a reliable candidate-to-claim mapping and adjudicated findings. For obligation, risk, and definition language the simple source scan found 0, 2, and 1 phrase matches, respectively; lexical hits alone do not define qualifying records. The obligation-classified T2 observation despite zero obligation-language hits illustrates a possible lexical miss.

| Predictor | Observed relationship | Assessment and confounder |
|---|---|---|
| Uncovered currency/scaled/percent hits | Do not track missed eligible identities consistently; C3 missed 42 despite only 10/8/4 uncovered hits. | **Weak**; dense cited spans hide distinct missing claims. |
| Uncovered obligation-language spans | No lexical hits; one observation was classified obligation only in T2. | **Inconclusive**; lexical rule is narrow and source has little obligation evidence. |
| Source span type / numeric density | Dense multi-value spans contain 126/176 unstable identities and 48/51 eligibility-unstable candidates. | **Strong descriptive cluster, not causal**; content and span structure are confounded. |
| `table_row` status | 53 source spans, zero cited; many are layout text or credits. | **Poor predictor here**; structural false positives. |
| Saturation | C1, E2, E3 saturated, yet unsaturated C3 missed the most eligible identities (42). | **Weak** completeness signal; threshold is returned count only. |
| Returned count | E2's 98 returned coincided with fewer misses (26), but E1's 67 and C3's 71 differed substantially. | **Weak** and confounded by prompt and projection. |
| Output tokens | All calls ended normally below 16,000; higher output sometimes accompanied fewer misses. | **Weak**; no hard output stop occurred. |
| Default vs temperature 0 | T0 key-figure eligibility Jaccard **.783**, versus C pairwise **.484/.071/.048**; overall evidence Jaccard T0 **.625**. | **Promising but inconclusive**: only two T0 calls and one slice. |

## Interpretation and one next intervention

**Confirmed:** there is long-tail identity churn **and** instability among records that the current provenance and key-figure eligibility rules can recognize. The supported classification is **C — a mixture of both**, with a material-candidate concern. It cannot be narrowed to “mostly Tier 3/4 table rows”: Tier 1–4 and forced Tier 1 are not evaluable, and the unstable eligible candidates cluster in dense multi-value spans rather than cited `table_row` spans. The effect on the actual Brief remains unknown because its selected top six also depend on leaf 1 and the full document.

**Recommend exactly one next intervention: A. a temperature-0 study**, still diagnostic and controlled, before any production change. T0 gave the clearest repeatability signal for both all evidence and key-figure eligibility, while the span-touch proxy missed most union-relative eligible omissions and is unsuitable as a conditional-recovery trigger. Temperature 0 uses the same token pricing and one call's latency; the two observed T0 calls cost about **$0.123** together at configured rates and averaged about **60 seconds** each. A larger paid study costs and waits roughly in proportion to its capped call count, with low diagnostic implementation complexity. Two calls do not justify changing production sampling. This recommendation addresses the risk of unstable materially relevant extraction without committing to an unvalidated coverage or recovery mechanism.

**Still unknown:** exact per-run and union tiers/scores, forced Tier 1, production `importantFindings`, selected Brief key figures, a gold recall rate, candidate-proxy precision/recall, and whether these leaf-2 findings transfer to leaf 1 or another document type. No tier movement is attributed to extraction absence without the required scorer context.

## Evidence boundary

**CONFIRMED FROM EXISTING DATA:** bundle and source identity, accepted-record projections, identity frequencies, key-figure eligibility under the fixed `asOf`, source-span clustering, candidate-scan counts, and the eight saved calls' saturation and token results.

**INFERENCE:** dense multi-value source content contributes to the observed instability; a larger temperature-0 study is the most useful next paid diagnostic. Neither conclusion establishes a causal mechanism or a document-wide effect.

**NOT EVALUABLE / NEEDS MORE DATA:** exact Stage A materiality tiers and forced Tier 1, selected Brief findings and key figures, source-candidate precision/recall, true extraction completeness, and transfer to leaf 1 or other documents.

Machine-readable per-identity data: `docs/intelligence-v2/diagnostics/unicef-leaf2-stability-analysis.json`. Both diagnostic files are intentionally uncommitted.
