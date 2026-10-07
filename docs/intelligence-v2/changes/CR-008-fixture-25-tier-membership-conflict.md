# CR-008: Resolve fixture 25 Tier 1 membership conflict

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | draft; approval required |
| **Approver** | Pending |
| **Approved on** | Pending |
| **Supersedes** | none |

## Contract sections affected

§5.0 maps metrics, facts, definitions and entities to Tier 3; §9.2 bands metric/fact base 0.36 and entity base 0.24 below Tier 1; §7.2 only fills Tier 1 from Tier 2; §7.4 T9 says Tier 1 contains every item the current `ImportantFindingsBuilder` and `TakeawayBuilder` surface. CR-006 allows only exact-score tie ordering to differ and makes selected set and tier membership strict. These requirements conflict on the existing annual-report corpus.

## Focused fixture 25 result

The diagnostic reused `DocumentIntelligenceAnalysisTest::annualReport()` by reflection; it did not edit the protected test. With fixed `asOf=2026-10-07`, the current `importantFindings` contains seven records. A pure scorer prototype using approved bases/bands/signals placed only one of those seven in Tier 1. The other six stay in Tier 3. No weight, band, class base, promotion or forced-rule parameter was tuned. The prototype and diagnostic test were removed after the failure; no materiality integration was committed.

| Existing important finding | Prototype score | Scored tier | Final tier | Contributing reasons |
|---|---:|---:|---:|---|
| Currency volatility exposure | 0.5635 | 2 | 1 | risk base 0.56 + severity 0.0035; admitted by Tier 1 minimum |
| Total financing approved | 0.4299769585253456 | 3 | 3 | metric base 0.36 + monetary magnitude 0.05142857142857142 + relative magnitude 0.018548387096774192 |
| Operating note 1 | 0.36 | 3 | 3 | fact base 0.36 |
| Operating note 2 | 0.36 | 3 | 3 | fact base 0.36 |
| African Development Bank | 0.24 | 3 | 3 | entity base 0.24 |
| Chief Financial Officer | 0.24 | 3 | 3 | entity base 0.24 |
| Nairobi | 0.24 | 3 | 3 | entity base 0.24 |

For all seven, structural prominence was skipped because the fixture has no span metadata, and boilerplate penalty was skipped with `span_type_unavailable`. No clamp, synthesis promotion or forced rule accounts for the six Tier 3 results. The approved CR-006 exact-score tie exception cannot change tier membership. Supplying chart comparability could raise a metric score, but it cannot raise the fact and entity bases to Tier 1 under the approved bands; this conflict is therefore not contingent on that incomplete prototype input.

The current takeaways' source IDs span many chart points. A separate diagnostic counted 21 distinct source IDs across important findings and takeaways, but that count is **not** treated as a Tier 1 requirement here because a takeaway may cite multiple records. The six important-finding mismatches above suffice to establish the conflict.

## Decision requested

Specify precisely whether fixture 25 requires V1 important findings to be Tier 1 records, or whether important findings may remain selected and visible in lower V2 tiers. If the latter, amend T9, §5.0 and the fixture's expected tier membership explicitly. If the former, provide an approved rule reconciling those six records with §7.2/§9.2 without tuning approved values or silently adding forced rules. Also specify how a takeaway with multiple cited source IDs maps to tier membership. No option is assumed or implemented by this draft.

## Impact and stop condition

- MaterialityScorer, ForcedItemRules, ImportantFindingsBuilder, TakeawayBuilder, EvidenceBudget and ChartCandidateBuilder integration remains stopped by the user-mandated fixture 25 gate.
- The approved CR-007 attribution matcher remains independently committed and tested.
- Flag-off behavior, provider requests, extraction, span sets, evidence budget, billing, migrations, frontend and Power BI remain unchanged.

**Approver notes:** Pending.
