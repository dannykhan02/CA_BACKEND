# CR-010: Resolve fixture 25 takeaway selection change under V2 formatting

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-08 |
| **Status** | draft; approval required |
| **Approver** | Pending |
| **Approved on** | Pending |
| **Supersedes** | none |

## Affected contract

§7.4 T9a / fixture 25 requires takeaway selection to stay the same as V1. Stage A Part 1 already enabled `ValueFormatter` for V2 takeaway amounts, while `TakeawayBuilder::duplicates()` selects candidates based on the **display text**. The approved formatter changes the duplicate decision and thus the selected set. CR-009's exact-score boundary exception applies to `importantFindings`, not to takeaway selection.

## Focused fixture 25 result

The diagnostic reused the unmodified `DocumentIntelligenceAnalysisTest::annualReport()` fixture at fixed `asOf=2026-10-07`. Chart candidates, chart order and rejection data were identical with the V2 flag off and on. Synthesis, trend, risk and obligation takeaway selections also matched. The metric takeaway set differed:

| V1 selected | V2 selected |
|---|---|
| Total financing time series | Total financing time series |
| Infrastructure share composition | Infrastructure share composition |
| Portfolio exposure categorical | Employees time series |

The selected references for the differing item were the four Portfolio exposure KPI records in V1 versus the two Employees KPI records in V2. No scorer rank, weight, band, forced rule or chart eligibility change caused this result. The new materiality path only supplied metadata to `TakeawayBuilder`; the candidate order and numeric quota stayed at their existing values.

## Exact cause

`TakeawayBuilder::amount()` uses the pre-V2 formatter when the flag is off and the already-committed `ValueFormatter` when it is on. The pre-V2 formatter removes trailing zeroes from an already formatted integer: `1,240` becomes `1,24` and `1,310` becomes `1,31` in the Employees prose. `TakeawayBuilder::words()` discards tokens shorter than three characters, so those malformed numerical tokens contribute no distinct words. The resulting Employees candidate shares three of its five retained words with the preceding Total financing takeaway; overlap is **3/5 = 0.6**, meeting the existing `DUPLICATE_OVERLAP` threshold. V1 suppresses Employees.

The approved V2 formatter preserves `1,240` and `1,310`; their `240` and `310` tokens raise the Employees set to seven retained words, so the same shared three words yield **3/7 ≈ 0.429**. V2 accepts Employees. It consumes the third metric-origin quota slot, leaving Portfolio exposure out. The difference is therefore a deterministic side effect of correcting display formatting while deduplicating on display text.

## Decision requested

Approve one exact policy for the fixture 25 gate: either explicitly permit this documented V2 takeaway selection change, or define a selection-time duplicate key independent of display formatting that preserves V1 selection while retaining the approved V2 display text. A key policy must specify the exact tokenization and precedence; no such rule is inferred or implemented here. The scorer values, patterns, chart rules, provider budget, and existing duplicate threshold must not be silently changed.

## Stop and scope

The temporary MaterialityScorer, ForcedItemRules, read model, builder integration and calibration tests were removed after this non-approved fixture 25 difference. No application-code change from this attempt was committed. The protected T9 baseline tests were not edited. No provider call, queue job, embedding call, extraction/OCR/chunking change, migration, frontend, billing, security, tenant-isolation or Power BI change was made. The synthesis evidence bound remains 16,000 bytes. Fixture 26, the final T9a finding-list comparison and the synthetic ADB diagnostic were not reached after the stop.

**Approver notes:** Pending.
