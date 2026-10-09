# Saved-response period and context-span audit

This is an **offline audit** of the 12 saved CONTROL and COLLECTOR_ONLY responses from the frozen UNICEF E100–E139 and India WASH E200–E238 study. It uses each call's raw model records and exact saved request body; no Anthropic call was made. Rules were recorded in [context-span-audit-rules.md](experiment-flags/context-span-audit-rules.md) before the first classification pass. Every case, cited value span, candidate span, payload-membership check and decision input is in [context-span-audit.json](experiment-flags/paid-study/analysis/context-span-audit.json). The [manual spot check](experiment-flags/paid-study/analysis/context-span-spotcheck.json) preserves rule corrections and source excerpts.

## Saved period-error baseline

The model returned 508 raw record instances; 223 had a non-null `period`. Counts include validator-rejected records because the purpose is to measure model-supplied period behavior. Repeated source claims across runs remain separate instances.

| Period category | Records | Share of 223 |
|---|---:|---:|
| CORRECT_AND_SUPPORTED | 131 | 58.74% |
| CORRECT_BUT_UNSUPPORTED_BY_CITATION | 0 | 0.00% |
| WRONG | 1 | 0.45% |
| AMBIGUOUS | 62 | 27.80% |
| NO_EXPLICIT_PERIOD_CONTEXT | 29 | 13.00% |

The **WRONG rate among evaluable records** is **1/132 = 0.76%**. Evaluable means the two correct categories plus WRONG; it excludes ambiguity and no-context cases. The unsupported-but-correct rate is **0/132 = 0%** among evaluable records and **0/223 = 0%** among all period-bearing records. The 29 no-context records are unsupported model-added periods; they are not treated as confirmed wrong calendar years.

The one clear WRONG case is `unicef_reduced-C1`, raw index 31: the model supplied `2024` for Bangladesh's 40% under-five stunting reduction citing **E122**. E122 states the reduction occurred **over a decade**. A `2024` appears two spans earlier in **E120**, but that is an India water/sanitation row. Spillover from that nearby row or a report-year cue is plausible, not provable. The full record, E122 text, nearby E120 candidate and page/ordinal inputs are preserved in the JSON.

Of the 62 AMBIGUOUS cases, 61 are single-year points from the OCR-flattened **E103** chart. Its payload contains multiple years, values and ratios without reliable column pairing. The remaining case is `unicef_reduced-C1` index 15: E108's COVID-19 phrase may qualify mitigation only, so the model's `2024` cannot be proved correct or wrong for the investment. The E103 aggregate record whose `period` is the caption range `2020–2024` is counted as supported **only as to the range**, not its individual year/value pairings.

## Same-payload context opportunities

A context span counts as a safe candidate only if it is in the **same exact request payload**, on the same page, within the configured 12-span ordinal locality, structurally owned by the value claim, unambiguous, free of an intervening heading/caption conflict, and addable within the three-ID cap. Mere proximity or the 2024 annual-report title does not establish ownership. The audit also examined the 285 period-null raw records for possible context-citation opportunities; this does not alter the 223-record period-error denominator.

| Potential context class | In same request payload | Outside request payload |
|---|---:|---:|
| Safe candidate | 4 record instances | 0 |
| Ambiguous candidate | 114 record instances | 0 |

All four safe instances are the **same one source relationship**, repeated in India C1, C2, K2 and K3: an E212 record about Swachh Bharat's shift in approach cites E212 alone, while immediately preceding **E211** says “By 2024, Swachh Bharat…” and E212 continues the same paragraph with “it.” E211 is in each exact saved India request, on the same page, one span away, and adds a second citation. These four instances are an **upper bound on possible context-citation benefit**, not four independent facts. No safe outside-chunk candidate was counted. The ambiguous group includes 61 already-cited E103 chart cases; those are not extra-citation opportunities. Another 61 unresolved records selected a candidate that was outside page/locality constraints despite being in the request; 197 selected nearby but structurally unsafe candidates. These categories are recorded per case, not counted as safe opportunities.

No explicit period-bearing candidate outside either frozen payload passed even the potential-candidate screen in the saved source material. A context span outside the exact call payload would be non-actionable regardless of locality. The audit does not infer that a wider chunk or future prompt would make it safe.

## Evidence-ID cap pressure

The saved raw-record citation distribution is:

| Existing cited spans | Record instances | Room for one context citation |
|---:|---:|---|
| 1 | 488 | Remains below cap (2/3) |
| 2 | 19 | Exactly reaches cap (3/3) |
| 3 | 1 | Exceeds cap (4/3) |

For the four proposed safe E212→E211 additions: **4 remain below cap, 0 exactly reach it, 0 exceed it**. No cap-exceeding case is included in the prompt-only upper bound. The current architecture's three-ID cap and validator were not changed.

## Manual source check and rule corrections

Twelve cases were inspected against the cited and candidate source text: safe, ambiguous, outside-locality, structurally unsafe, already-supported and already-cited-conflict examples. No `NO_CONTEXT_FOUND` case was available under the candidate-screen rule; nearby explicit phrases existed for every unresolved record, though often unrelated. The current labels had **0/12 manual disagreements**. This was an AI-agent source check, not independent human adjudication.

The spot-check artifact also records corrections made after the first automated pass: missing `decade-long` and `Over 10 years` regex forms, overconfident treatment of E108's COVID timing, unsupported `2024` on E112/E124, and the E103 caption-range exception. Initial and corrected effects are disclosed there; labels were regenerated for all 508 records after each rule correction.

## Scope and interpretation

Saved diagnostic evidence covers **only these two previously captured chunks and three runs per variant**. This is a small offline sample. Findings are indicative, not general proof across document types. Opportunity counts are an **upper bound** on possible benefit. Offline availability of safe context does **not** prove that a model will cite it correctly in a future prompt experiment. Safe-context availability is **not** a measured recall improvement. No production code, request body, benchmark, model setting or provider output was changed for this audit.
