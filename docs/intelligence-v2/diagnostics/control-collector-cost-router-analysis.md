# CONTROL versus COLLECTOR: cost and routing feasibility

**Decision: D — the saved evidence is insufficient to justify adaptive routing.** This is an offline analysis of the frozen 12 calls only. It does not revise the study's **REJECT COLLECTOR** result: mean cost exceeded its preregistered 25% gate on both chunks. No provider call, prompt edit, production edit, push, merge, or deploy was made.

## Scope and methods

The inputs are the exact `paid-study/*.request.json`, `*.response.json`, `*.metadata.json`, saved validator/provenance replays, frozen gold and negative reviews, original `unicef-full-extracted.txt`, and `unicef-source-spans.json`. The [reproducible offline builder](experiment-flags/paid-study/analysis/build-cost-router-analysis.py) emits the JSON tables linked below. `C` is CONTROL; `K` is COLLECTOR_ONLY. Cost is the saved actual USD charge, equivalent in these cells to $1 per million input tokens plus $5 per million output tokens. Calls are paired by run, although provider output is variable.

Serialized field sizes use compact UTF-8 JSON **values**. Their `bytes / 4` token proxy is only a comparison aid, not Anthropic's tokenizer; actual response token counts come from provider metadata. The decomposition holds CONTROL output tokens per returned record fixed to estimate the count effect, then assigns the remainder to changed length per record. It is accounting, not a causal experiment.

## Every call

| Chunk | Call | Input | Output | Cost USD | Returned | Accepted | Rejected | Output/returned | Output/accepted |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|
| UNICEF E100–E139 | C1 | 4,019 | 4,811 | .028074 | 41 | 41 | 0 | 117.34 | 117.34 |
| UNICEF E100–E139 | C2 | 4,019 | 5,974 | .033889 | 42 | 42 | 0 | 142.24 | 142.24 |
| UNICEF E100–E139 | C3 | 4,019 | 4,960 | .028819 | 30 | 30 | 0 | 165.33 | 165.33 |
| UNICEF E100–E139 | K1 | 4,022 | 5,837 | .033207 | 40 | 40 | 0 | 145.93 | 145.93 |
| UNICEF E100–E139 | K2 | 4,022 | 8,543 | .046737 | 66 | 66 | 0 | 129.44 | 129.44 |
| UNICEF E100–E139 | K3 | 4,022 | 10,889 | .058467 | 78 | 78 | 0 | 139.60 | 139.60 |
| India E200–E238 | C1 | 4,128 | 4,642 | .027338 | 36 | 30 | 6 | 128.94 | 154.73 |
| India E200–E238 | C2 | 4,128 | 3,650 | .022378 | 28 | 26 | 2 | 130.36 | 140.38 |
| India E200–E238 | C3 | 4,128 | 2,869 | .018473 | 24 | 19 | 5 | 119.54 | 151.00 |
| India E200–E238 | K1 | 4,131 | 5,602 | .032141 | 46 | 45 | 1 | 121.78 | 124.49 |
| India E200–E238 | K2 | 4,131 | 6,223 | .035246 | 41 | 39 | 2 | 151.78 | 159.56 |
| India E200–E238 | K3 | 4,131 | 5,452 | .031391 | 36 | 29 | 7 | 151.44 | 188.00 |

[Per-call JSON](experiment-flags/paid-study/analysis/per-call-decomposition.json) includes typed counts, provenance, key-figure eligibility and identity collisions for each call.

## Why COLLECTOR costs more

| Three-run mean per call | UNICEF CONTROL | UNICEF COLLECTOR | India CONTROL | India COLLECTOR |
|---|---:|---:|---:|---:|
| Cost USD | .030261 | .046137 | .022730 | .032926 |
| Returned records | 37.67 | 61.33 | 29.33 | 41.00 |
| Accepted records | 37.67 | 61.33 | 25.00 | 37.67 |
| Output tokens | 5,248.33 | 8,423.00 | 3,720.33 | 5,759.00 |
| Extra cost | — | **.015876 (+52.47%)** | — | **.010196 (+44.86%)** |
| Extra output tokens | — | **3,174.67** | — | **2,038.67** |
| Count effect at CONTROL tokens/record | — | **+3,297.63** | — | **+1,479.68** |
| Changed length per record | — | **−122.96** | — | **+558.99** |

Input differs by only three tokens per call, or $0.000003. Nearly all extra cost is output. UNICEF emitted 23.67 more records per call, yet slightly fewer tokens per returned record in aggregate. Its output increase is fully explained by count, offset by shorter average records. India emitted 11.67 more records and its average record grew; count explains about 73% of its 2,039 extra output tokens and length about 27%.

No exact premerge identity collisions appeared in any call. An additional mechanical check required the same normalized value, kind, cited evidence IDs, period, label and every other structured field, while allowing `reference` wording to differ. It found **two** cross-variant equivalents in India (one carrying shared gold, one non-gold), and none in UNICEF or repeated within COLLECTOR. This is deliberately narrower than semantic deduplication and explains negligible cost. Validator rejection does not explain the rise: UNICEF had zero rejections in either variant; India COLLECTOR averaged one **fewer** rejection per call (10 versus 13 across three runs). The saved India rejection reasons are predominantly invalid date metadata.

The extra accepted output has mixed value. Some sampled non-gold COLLECTOR records were judged supported, while other sampled records were unsupported. The full non-gold population was never independently adjudicated. Therefore the extra record count cannot be equated with either useful evidence or noise. [Cost comparison JSON](experiment-flags/paid-study/analysis/cost-comparison.json).

## Field-level output size

Across six calls per variant, value-only serialized bytes and mean bytes per returned record were:

| Field | CONTROL total / mean | COLLECTOR total / mean | Increase |
|---|---:|---:|---:|
| `reference` | 19,835 / 98.68 | 41,295 / 134.51 | **21,460** |
| `label` | 9,397 / 46.75 | 13,934 / 45.39 | 4,537 |
| `value` | 11,242 / 55.93 | 12,979 / 42.28 | 1,737 |
| `subject` | 2,981 / 14.83 | 3,920 / 12.77 | 939 |
| `evidence_ids` | 1,671 / 8.31 | 2,540 / 8.27 | 869 |
| `period` | 1,136 / 5.65 | 1,760 / 5.73 | 624 |
| `kind` | 1,524 / 7.58 | 2,300 / 7.49 | 776 |
| Other schema values combined | 11,624 / 57.83 | 18,468 / 60.15 | 6,844 |

The largest field increase is `reference`: +11,206 bytes in UNICEF and +10,254 in India across each chunk's three runs. In India it also lengthened sharply per record (55 to 123 bytes); `value` grew by 2,627 bytes. In UNICEF `value` shrank by 890 bytes despite more records. All required schema keys, commas and braces repeat for every record; value-only bytes increased 37,786 while whole serialized record bytes increased 61,530, leaving 23,744 bytes of repeated JSON scaffolding. These are estimates of response structure, not exact provider token attribution.

More metadata was populated in absolute terms because more records were returned. For example, UNICEF populated `period` in 66 CONTROL versus 88 COLLECTOR records and `quantity_kind` in 88 versus 145. India populated `unit` in 23 versus 39. These increases do not establish a broader metadata practice per record; the fixed schema and record count dominate. The user-facing free text is concentrated in `reference`, `label` and `value`.

The cited `evidence_ids`, kind, period, unit, dates and other typed metadata are structured evidence. Their extra bytes mostly follow additional records; a fixed schema requires null fields even when unpopulated. The long `reference`, and narrative `value` for facts, are plausible compression targets. The field table does **not** establish that any field can safely be removed. [Full field breakdown JSON](experiment-flags/paid-study/analysis/field-size-breakdown.json) reports aggregate and chunk-specific totals, means, populated counts and token proxies for all 18 emitted fields, including confidence and aliases.
The saved schema has no separate `description` field; explanatory narrative appears chiefly in `reference` and sometimes `value`.

## Gold recovery and useful extra output

Gold recall is 17 UNICEF items and 12 India items. The following is the **union across three runs of each variant**, not a single-run result:

| Chunk | Found by both | CONTROL only | COLLECTOR only | Missed by both |
|---|---|---|---|---|
| UNICEF | 15: ordinals 1–11, 13–15, 17 | 12 | **none** | 16 |
| India | 11: ordinals 1–4, 6–12 | none | **none** | 5 |

Thus COLLECTOR introduced **no gold item that CONTROL never found in its three runs**. It improved the *mean consistency* of recovery: UNICEF 13.67 to 14.33 and India 8.33 to 9.67. Paired-run COLLECTOR-only gold counts were UNICEF 1, 1, 2 and India 4, 0, 3; CONTROL-only counts were UNICEF 0, 1, 1 and India 0, 1, 2. Exact item descriptions and every paired intersection are in [gold recovery JSON](experiment-flags/paid-study/analysis/gold-recovery-comparison.json).

For COLLECTOR records, the frozen gold match and validator were used first, then the existing **sampled** precision judgment where available. Across the six COLLECTOR calls this yields 9 records carrying paired-run gold recovery, 60 records carrying shared gold, 66 sampled supported non-gold records, 18 sampled unsupported non-gold records, 143 accepted non-gold records without a conclusive judgment, 10 rejected records, and one non-gold mechanical duplicate. The other cross-variant mechanical equivalent carries shared gold and stays in that class. One record can match more than one gold item, so record counts differ from gold-item counts. The sampled precision review was AI judged with mechanical blinding, not independently human adjudicated. Partial support remains unadjudicated here; no new materiality label was invented. The frozen negative-set rates were 5.56% CONTROL versus 0% COLLECTOR for UNICEF, and 0% for both India variants.

## Cost per additional recovered gold item

These use differences of **three-run means**, so the denominator is net mean recall gain, not the number of unique items in the union above.

| Chunk | Extra cost per call | Net gold gain per call | USD per net gold | Extra accepted / net gold | Extra output tokens / net gold |
|---|---:|---:|---:|---:|---:|
| UNICEF | .015876 | .667 | **.023814** | 35.5 | 4,762 |
| India | .010196 | 1.333 | **.007647** | 9.5 | 1,529 |
| Both chunks, one call each | .026073 | 2.000 | **.013036** | 18.17 | 2,607 |

Across all 12 calls the same combined ratio is $0.078218 extra for six *net run-level* gold matches. These denominators are too small and variable to support a stable economic threshold. In UNICEF run 1, COLLECTOR even returned one fewer record than CONTROL; in India run 2, it recovered no paired-run extra gold.

## Deterministic downstream replay

The preserved offline replay already passed each exact response through the current `EvidenceSchema`, `EvidenceMerger::identity`, `TypedEvidenceProjector` and key-figure eligibility predicate. Summed over three runs:

| Chunk / variant | Accepted | Typed money / percent / count | Other typed / no typed value | Origin document / unknown | Key-figure eligible | Premerge identity collisions |
|---|---:|---:|---:|---:|---:|---:|
| UNICEF CONTROL | 113 | 16 / 14 / 15 | 3 / 65 | 66 / 47 | 10 | 0 |
| UNICEF COLLECTOR | 184 | 24 / 55 / 23 | 6 / 76 | 145 / 39 | 14 | 0 |
| India CONTROL | 75 | 3 / 2 / 10 | 8 / 52 | 42 / 33 | 3 | 0 |
| India COLLECTOR | 113 | 3 / 1 / 21 | 2 / 86 | 48 / 65 | 3 | 0 |

UNICEF gained 71 accepted rows, including 41 more typed percentages and four more eligible key figures. India gained 38 accepted rows but no additional eligible key figure; its document-origin share fell from about 56% to 43%. Exact premerge identities did not collide within any call. Cross-run or cross-variant similarity is not a safe merge estimate because records can differ structurally and semantic deduplication is excluded.

Materiality tiers and important findings cannot be faithfully replayed from these isolated chunks. The current read model needs a full document evidence collection, stored span rows, chart comparability, cited synthesis, risk/deadline status and entity context. Supplying empty substitutes would change scores and selection. The saved replay supports typed/provenance/key-figure conclusions only; it does not show whether the extra rows would enter final findings.

## Original document and chunk profiles

The original extracted UNICEF document and 360 stored span rows are available. It covers 22 pages, 58,018 Unicode characters, about 19,512 planner-style bytes/3 tokens, and 15 heading spans. Measured per 1,000 whitespace tokens: 47.05 numeric expressions, 8.72 percentages, 10.21 currency expressions and 5.16 years/dates. There are 96 table-like lines among nonblank lines (6.2%). Existing planner density is `dense=false`, numeric ratio .044, table-line ratio about .062 and configured expected rate 4 records/1,000 estimated tokens. Its whole-document pressure of 78.05 records is **only a planning estimate**, not observed extraction yield.

| Saved slice | Pages | Chars | Bytes/3 tokens | Spans | Numeric / 1k | % / 1k | Currency / 1k | Year/date / 1k | Table-line ratio | Heading spans | Planner pressure |
|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| UNICEF E100–E139 | 5–8 | 7,288 | 2,445 | 40 | 81.69 | 31.92 | 13.15 | 5.63 | .026 | 3 | 9.78 |
| India E200–E238 | 14–17 | 7,929 | 2,665 | 39 | 26.07 | .84 | 2.52 | 5.05 | .023 | 2 | 10.66 |
| Earlier E033–E186 diagnostic range | 3–12 | 25,301 | 8,488 | 154 | 61.51 | 13.97 | 15.58 | 5.91 | .064 | 6 | 33.95 |

The two paid chunks both classify as `dense=false` under current planner thresholds (.18 numeric ratio or .30 tabular ratio). The full document is sparse by those tests, while the UNICEF study region has substantially higher numeric, percentage and currency density than the document. That is a real local contrast. Yet India is less numeric and gained *more* mean gold recall from COLLECTOR. Simple numeric density therefore failed as a predictor of where CONTROL would miss gold in these two observations. India has slightly higher estimated record pressure because it is longer. The earlier diagnostic E033–E186 range is much larger and overlaps UNICEF E100–E139; it is **not an independent paid comparison**. It has saved request data but no matching three-run COLLECTOR cost/recall outcome.

Page count, text length, stored span count/types, existing planner density and estimated pressure are already available. Regex numeric, percent, currency and year counts and local percentiles are cheap to derive. They are noisy: OCR line breaks split numbers, dates and currencies; years can be axis labels; repeated chart labels inflate counts; and the planner's fixed 4/6 records-per-1,000-token assumption badly underpredicts the observed 24–78 returned records in these slices. Table-like detection is especially uncertain on PDF text, where layout is flattened. Across 17 overlapping 40-span windows of the parent document, estimated pressure ranges 4.03–13.03 (median 9.88) and numeric density 13.12–144 per 1,000 whitespace tokens. The windows are descriptive, correlated, and not additional study examples. [Feature JSON](experiment-flags/paid-study/analysis/router-feature-table.json) includes each window and each chunk's parent-relative position.

## Router backtest

The architecture under consideration is original-document profile plus chunk profile → `STANDARD`, `COMPACT_COLLECTOR` or `SPLIT_FIRST`. The table substitutes observed COLLECTOR_ONLY behavior for the untested compact mode; it does **not** predict the compact prompt's behavior or actual split-first cost. Figures are mean USD and sum of chunk gold matches for **one call on each paid chunk**.

| Interpretable rule | UNICEF route | India route | Proxy cost | Proxy gold matches |
|---|---|---|---:|---:|
| STANDARD everywhere | STANDARD | STANDARD | .052990 | 22.00 |
| COLLECTOR everywhere reference | COMPACT_COLLECTOR | COMPACT_COLLECTOR | .079063 | 24.00 |
| Chunk pressure ≥10 | STANDARD | COMPACT_COLLECTOR | .063187 | 23.33 |
| Pressure ≥10 or numeric density ≥80/1k | COMPACT_COLLECTOR | COMPACT_COLLECTOR | .079063 | 24.00 |
| Numeric density ≥ parent document | COMPACT_COLLECTOR | STANDARD | .068867 | 22.67 |
| Split at pressure ≥20, else pressure ≥10 | STANDARD | COMPACT_COLLECTOR | .063187 | 23.33 |

No paid chunk reaches the split-first threshold; its cost and recall remain unobserved. The pressure-only rule happens to select India, the chunk with the larger mean gain, but a threshold at 10 lies between just **two** chunks (9.78 and 10.66) and is not robust evidence. Numeric density selects UNICEF, the smaller mean gain. The document-relative rule adds no useful distinction beyond chunk numeric density here. [Routing JSON](experiment-flags/paid-study/analysis/candidate-routing-backtest.json) includes the observed CONTROL and COLLECTOR mean recall under every rule.

Document context is useful for describing dense local regions inside a sparse document and could inform chunk planning. It has **no demonstrated incremental predictive value** for choosing broader extraction: there is only one parent document and two paid regions. A document-level decision would send both chunks the same way and miss their different response patterns. Any later router evaluation needs more independent documents and chunks with frozen outcomes; no production classifier was fit here.

## Prompt-caching readiness

Current `AnthropicClient::extractChunk` constructs a long system instruction and JSON schema. In the saved requests the system text is 3,746 characters for CONTROL and 3,850 for COLLECTOR, and the compact serialized `output_config` is 1,276 characters; each is identical across chunks within its variant. The saved request marks the system text with `cache_control: ephemeral`. The user message then varies by document name, page range, record limit, optional continuation state and labeled source spans. CONTROL and COLLECTOR system texts differ, and the study's saved cache creation/read usage is zero on every call. A future shared CORE plus small variable module could preserve a stable prefix **if** the CORE precedes all variable instructions and source content; the current per-variant system already supplies a stable candidate prefix within each mode. Whether restructuring helps caching, whether the schema is cacheable in the desired position, hit rates and savings all need a separate implementation check. No savings are assumed and caching was not changed.

## Compact design inputs and decision

The decomposition supports testing concise `reference` wording, shorter narrative `value` only where meaning and provenance remain intact, and elimination of repeated explanatory text in records. Mechanical duplicate suppression is safe to examine but found just one non-gold extra record here. Selective activation could reduce cost if a future backtest finds predictive features; `SPLIT_FIRST` remains a capacity fallback for genuinely high pressure, with no saved efficacy measurement here. None of these ideas is a production prompt or a suggestion to drop fields, loosen validation, infer metadata or prioritize only “important” facts.

**Final choice: D.** Both record volume and free-text length contribute, but the two chunks do not establish a reliable rule for directing broader extraction where it pays. The existing cost gate still rejects broad COLLECTOR use.

- **Document + chunk routing:** worth investigating with a larger independent saved set; not justified for deployment by this study. Document context adds no proven routing gain here.
- **Prompt caching:** worth a later implementation and measurement check, with no savings assumed.
- **SPLIT_FIRST:** keep as a possible deterministic capacity mode; its benefit is unmeasured in these calls.
- **AI document classifier:** postpone. The cheap profile is sufficient for the current feasibility question, and importance classification is outside scope.

## Machine-readable outputs

All files are under [paid-study analysis](experiment-flags/paid-study/analysis/): `per-call-decomposition.json`, `field-size-breakdown.json`, `gold-recovery-comparison.json`, `cost-comparison.json`, `router-feature-table.json`, and `candidate-routing-backtest.json`.
