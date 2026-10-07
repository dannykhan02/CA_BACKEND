# CR-008: Resolve fixture 25 Tier 1 membership conflict

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | approved |
| **Approver** | Stage A approver |
| **Approved on** | 2026-10-07 |
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

## Approved resolution

The earlier T9 wording was inconsistent with the already-approved `class_base` values and tier bands. `importantFindings` and Tier 1 are separate. The former keeps its legacy-surface meaning: top `MAX` by score, `MAX_PER_STEM`, and per-kind rules for non-forced items, independent of Tier 1. Fixture 25 checks its selected set and order strictly, except exact-score ties use §9.5. Takeaway selection and chart candidate order remain strict. Tier 1 must contain every record V1 classifies as `critical_risk`, `high_risk` or `upcoming_obligation`; it may otherwise be smaller than `importantFindings` and is never padded. T9 does not constrain tier membership for metrics, facts, definitions or entities. No approved weight, band, class base, signal, promotion, pattern or forced rule changes.

## Impact and stop condition

- MaterialityScorer, ForcedItemRules, ImportantFindingsBuilder, TakeawayBuilder, EvidenceBudget and ChartCandidateBuilder integration may resume under the amended fixture 25 gate; any selected-set or non-tie-order difference remains a stop condition.
- The approved CR-007 attribution matcher remains independently committed and tested.
- Flag-off behavior, provider requests, extraction, span sets, evidence budget, billing, migrations, frontend and Power BI remain unchanged.

**Approver notes:** Approved T9a/T9b separation above. The approved scorer values remain fixed.
