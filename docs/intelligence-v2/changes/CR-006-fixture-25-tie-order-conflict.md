# CR-006: Resolve fixture 25 ordering conflict with the approved tiebreak

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | draft; approval required |
| **Approver** | Pending |
| **Approved on** | Pending |
| **Supersedes** | none |

## 1. Contract sections affected

§5.0 and §7.4 T9 require existing `ImportantFindingsBuilder` order to be preserved. §9.5 prescribes a different total tiebreak. §22.3 fixture 25 requires both. A choice between these requirements needs approval; no change to either has been made.

## 2. Observed fixture 25 failure

The new calibration harness reused the existing `DocumentIntelligenceAnalysisTest::annualReport()` corpus without editing that protected test. It compared the current `importantFindings` order with the order produced by the approved class bases, weights and §9.5 tiebreak among the same selected findings. It failed on the first evaluated corpus case:

| Position | Existing order | Approved scorer order |
|---|---|---|
| 1 | Currency volatility exposure | Currency volatility exposure |
| 2 | Total financing approved | Total financing approved |
| 3 | Operating note 1 | Operating note 2 |
| 4 | Operating note 2 | Operating note 1 |
| 5 | African Development Bank | Chief Financial Officer |
| 6 | Chief Financial Officer | Nairobi |
| 7 | Nairobi | African Development Bank |

Membership changed: **none** in this evaluated case. The takeaway selection and chart candidate order were not changed or calibrated; scorer integration stopped at the first failed fixture-25 check.

## 3. Every contributing reason for the evaluated findings

| Findings | Raw / score | Non-zero contributions | Skipped reasons |
|---|---:|---|---|
| Currency volatility exposure | 0.5635 | `kind_class:risk` 0.56; `severity` 0.0035 | `structural_prominence:span_data_unavailable`; `boilerplate_penalty:span_type_unavailable` |
| Total financing approved | 0.4299769585253456 | `kind_class:metric` 0.36; `monetary_magnitude` 0.05142857142857142; `relative_magnitude` 0.018548387096774192 | same two span reasons |
| Operating note 1; Operating note 2 | 0.36 each | `kind_class:fact` 0.36 each | same two span reasons |
| African Development Bank; Chief Financial Officer; Nairobi | 0.24 each | `kind_class:entity` 0.24 each | same two span reasons |

For the reordered pairs, the difference comes from **§9.5 tiebreak**, not a base-class difference, signal contribution, clamp, synthesis promotion or forced rule. All facts in the swapped pair have equal scores; all three entities have equal scores. Their fixture span start offsets are also equal, so §9.5 reaches `identity`. V1 orders those ties using confidence and reference; confidence is expressly forbidden as a V2 signal or tiebreak input. Random fixture identity salts can vary the exact permutation, but cannot make the two ordering rules equivalent.

## 4. Decision requested

Choose an explicit contract resolution before materiality integration resumes:

1. Preserve T9's exact existing order by approving a narrowly specified V2 presentation tiebreak for equal-score findings, with its scope and interaction with §9.5 stated; or
2. Approve the observed ranking change and amend T9/fixture 25 and affected compatibility language, including exactly which outputs may differ.

Do not silently choose an option. The Stage A instruction forbids tuning weights, bands or class bases, weakening fixture 25, or modifying protected baseline tests. No such change was made.

## 5. Impact and alternatives

- Provider calls, request bytes, evidence budget, billing, Power BI, database schema, extraction, span sets and frontend: unchanged by this CR.
- Flag-off behavior: unchanged.
- Fixture 26's isolated prototype passed: four critical risks were all forced into Tier 1 despite `per_kind = 3`; that does not resolve fixture 25.
- Doing nothing leaves materiality integration stopped and the V2 flag-off behavior intact.

## 6. Conformance and approval

After approval, rerun fixture 25 across the full existing corpus, fixture 26, focused scorer tests and the full PostgreSQL suite. Any approved ranking difference must be recorded case by case. No scorer integration is authorized by this draft.

- [ ] Contract §§5, 7, 9 and 22 reconciled.
- [ ] Every approved ranking difference enumerated, or an approved exact tiebreak preserves the old order.
- [ ] Fixture 25 and 26 pass without changing weights, bands or class bases.

**Approver notes:** Pending.
