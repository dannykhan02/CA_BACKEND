# Northstar V10 controlled live evaluation

Reported 22 October 2026 · one authorized Claude Haiku 5.5 request · branch `feat/docintel-v10-live-eval-prep`.

## Execution and integrity

The prepared command ran once. PHPUnit passed **1 test, 22 assertions**. The provider returned HTTP 200 with `end_turn`; the real V10 parser accepted complete JSON, and no transport or structured-output retry occurred. The response contained 20 KPI observations, five narrative insights and no model chart JSON. The same parsed response then passed through `GenerateInsightsJob`, identity resolution, grounding, persistence, collector, integrity, analytical builders and API serialization. No hand-written observation was substituted in live mode.

| Check | Live result |
|---|---:|
| Provider requests | 1 |
| Model / prompt | `claude-haiku-5-5` / V10 |
| Original PDF SHA-256 | `4507eb76cb2dfce4be55c23e6572a70e473a1bab520071734065f64755384f2f` |
| Extracted text SHA-256 | `a4722cfdb3795de38553953d1c12521053a5368232bf3a0cbcd4a952712fa8da` |
| Source spans | 126 |
| V10 prompt template SHA-256 | `2be7ab2584d154df68437fb48b0dcc20e9ebecdf07e628c76793a01c8e1f88d0` |
| Rendered prompt SHA-256 | `9bb9433df3f53439ec41428609b7bb2ae5dc1254a435737f7dbc50ede2d50717` |
| Actual input / output tokens | 8,209 / 7,033; output includes 3,264 thinking tokens |
| Output cap / stop reason | 14,000 / `end_turn` |
| Provider latency / full insights-call latency | 25,840 ms / 25,890 ms |
| Usage-based estimated cost | **$0.004337** |
| Conservative reserved maximum | $0.009956 under the $0.01 process guard |

The exact 21,924-byte provider response is retained at `storage/framework/testing/docintel-v10-evaluation/live-20261021-204955-e0085f8755c8.json.provider-response.json` (SHA-256 `622cca54e2ea149e36ada8fb3d177d93ea00613cc853f204d42d83bf023de6aa`). The 110,676-byte evaluation diagnostic is its sibling without `.provider-response`. Both files are mode 0600 inside a mode-0700 git-ignored directory. Neither is committed. The test transaction rolled back: a read-only check afterward found zero users, documents, V10 prompt rows, AI runs, KPIs and evidence in the disposable database.

## Model facts versus independent verification

Manual comparison with the original PDF found **20/20 numerical value-to-source-cell and actual/target/proposed associations correct**. This is the same source-facing factual standard used for the frozen V9 manual review. It does **not** mean the full observation contract was satisfied: only the eight Q2/Q3 financial claims have exact gold-matching label, unit, full period, subject and basis. The other twelve include bare-quarter or `Q3 target` periods, missing count units, differing scope/label semantics, or facts outside the 29-case gold set. A source-correct value is not verified without its field-role citations.

All **20/20** observations were rejected from source-verified analytics. The 20 KPI rows existed during the test, but **zero** metric evidence rows were accepted; the API marked all 20 ineligible and exposed zero evidence mappings. The first decisive rejection for every row follows. Later field checks were not reached where an earlier check rejected, so downstream `unit_unverified` and `revision_proof_missing` resource labels do not prove the written unit or source revision was wrong.

| Live index | Observation | First decisive rejection | Source relationship |
|---:|---|---|---|
| 0 | Revenue Q3, 51.0 KES million | `subject_source_mismatch` | Company scope cited E029 value row; governing entity is E025. |
| 1 | Revenue Q2, 46.0 KES million | `subject_source_mismatch` | Same E029 subject citation. |
| 2 | Operating costs Q3, 41.0 KES million | `subject_source_mismatch` | Company scope cited E030 value row. |
| 3 | Operating costs Q2, 36.0 KES million | `subject_source_mismatch` | Same E030 subject citation. |
| 4 | Operating profit Q3, 10.0 KES million | `subject_source_mismatch` | Company scope cited E031 value row. |
| 5 | Operating profit Q2, 10.0 KES million | `subject_source_mismatch` | Same E031 subject citation; repeated value is correctly aligned to Q2. |
| 6 | Operating margin Q3, 19.6% | `subject_source_mismatch` | Company scope cited E032 value row. |
| 7 | Operating margin Q2, 21.7% | `subject_source_mismatch` | Same E032 subject citation. |
| 8 | On-time delivery actual Q3, 87% | `subject_source_mismatch` | Claimed company scope cited E048 numerical row, which names deliveries but not the company. |
| 9 | On-time delivery actual Q2, 90% | `subject_source_mismatch` | Same E048 subject citation; period is only `Q2`. |
| 10 | On-time delivery target, 95% | `period_ambiguous` | `identity.period` is `Q3 target`, mixing period and basis; E048 also lacks claimed company scope. |
| 11 | Customer complaints actual Q3, 133 | `subject_source_mismatch` | E049 lacks claimed company scope; unit is null although the source defines cases. |
| 12 | Customer complaints actual Q2, 96 | `subject_source_mismatch` | Same E049 citation and omitted unit. |
| 13 | Customer complaints ceiling, ≤ 90 | `period_ambiguous` | `Q3 target` used as period; unit is null. The `≤` operator was preserved. |
| 14 | Shipments actual Q3, 14,900 | `subject_source_mismatch` | E047 lacks claimed company scope; unit is null. |
| 15 | Shipments target, 14,500 | `period_ambiguous` | `Q3 target` used as period; unit is null. |
| 16 | Fleet intervention proposed funding, 1.20 KES million | `concept_row_mismatch` | `proposed funding` appears in E072 header, while E074 value row names the initiative. |
| 17 | Warehouse staffing proposed funding, 0.75 KES million | `concept_row_mismatch` | Same header-to-row concept relationship, E072/E075. |
| 18 | Complaint tooling proposed funding, 0.45 KES million | `concept_row_mismatch` | Same header-to-row concept relationship, E072/E076. |
| 19 | Total proposed funding, 2.40 KES million | `concept_row_mismatch` | E072 header says funding; E077 total row and preceding caption do not alone meet the verifier's same-row concept test. |

First-rejection totals: **13** `subject_source_mismatch`, **3** `period_ambiguous`, **4** `concept_row_mismatch`. The three targets also used value rows that do not prove the claimed company, but the period check stopped first. The total-proposed row's subject citation was likewise not checked after its concept rejection. These are potential additional deficiencies, not completed verification verdicts.

## Gold coverage and analytics

The corrected strict scorer matched **8/29** gold facts: Q2 and Q3 for revenue, operating costs, operating profit and operating margin. **0/29** had accepted evidence. Manual source-value/basis review links 16 emitted values to the 29 gold cases, including operational values and the proposed total, but eight of those links lack full gold-aligned fields; this 16/29 figure is *value coverage*, not verified gold coverage. Four source-correct emitted values are outside the 29-case gold set: three proposal line items and the shipments target.

The model omitted all four Q1 financial observations. It therefore supplied only **8/12** financial cells and **0/4** complete three-quarter financial series even before grounding. It also omitted the Q1 delivery and complaint values, operating-cost function breakdown, separate function total, north-region revenue and quarter-end employees. The four financial Q2/Q3 source cells were correctly aligned by row and column, but their company citations failed. Neither Q3 delivery actual nor its 95% target gained evidence. There were **zero trusted charts, zero deterministic comparisons, and zero API evidence mappings**. The collector had no eligible observations, so this run provides no evidence of a chart or comparison-builder defect.

| Measure | Frozen V9 live | V10 live | Hand-cited V10 offline test |
|---|---:|---:|---:|
| Emitted observations | 7 | **20** | 14 deterministic inputs |
| Manually source-correct numerical facts | 7/7 | **20/20** value/cell/basis associations, with field qualifications above | 14/14 by construction |
| Strict exact gold matches | 4 after scorer repair | **8** | 12 financial; delivery scopes differ from strict gold |
| Independently eligible | 1 | **0** | 14 |
| Complete financial three-quarter series | 0/4 | **0/4** | 4/4 |
| Trusted charts / deterministic comparisons | 0 / 0 | **0 / 0** | 4 / 8 |
| Input / output tokens | 8,266 / 5,196 | **8,209 / 7,033** | No provider use |
| Insights-call latency / estimated cost | 20.880 s / $0.003425 | **25.890 s / $0.004337** | No provider use |

The V10 cost was approximately 26.6% above the one-document V9 call and remained below the reserved maximum. This is one sample, not a model reliability rate. The offline 14-observation fixture demonstrates what the backend does with adequate citations; it does not repair this live response.

## Failure localization

1. **Model generation:** Haiku produced more standalone observations than V9 and no chart JSON, but omitted Q1 financial operands and several other gold facts. It also encoded three target column labels as `identity.period = "Q3 target"`, left count units null, and supplied bare Q2/Q3 operational periods. These defects alone prevent complete gold coverage.
2. **Citation selection:** This is the dominant acceptance blocker. For 13 rows, the first rejection was an unsupported company citation to the value row. The prompt expressly instructed the model to cite a genuine governing entity header; E025 is available, and the offline fixture uses it to ground all twelve financial cells. The V9 run showed the same financial citation error. The V10 prompt did not correct that behavior in this sample.
3. **Grounding:** The four source-correct proposal amounts reached `concept_row_mismatch` because the model's concept appears in the cited funding header rather than the value row. This is a possible coverage limitation for header-governed concepts. The check failed before later unit, subject and basis checks, so the run does not establish that those four submissions would otherwise be fully eligible. No invalid table-cell association was accepted.
4. **Analytical composition:** No candidate passed grounding, so the builders had no eligible operands. The offline fixture generated four charts and eight comparisons through these same production builders; this live zero is downstream of generation and citation failures, not a demonstrated composer failure.

V10 remains inactive in production. No second paid call, prompt change, token-cap increase, deploy, push, merge, migration, frontend change or Intelligence V2 activation occurred.
