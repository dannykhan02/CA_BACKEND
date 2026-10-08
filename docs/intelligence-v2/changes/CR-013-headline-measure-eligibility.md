# CR-013: Align headline_measure with rule 70

| | |
|---|---|
| **Author** | Stage A hardening review |
| **Date** | 2026-10-08 |
| **Status** | approved |
| **Approver** | User, 2026-10-08 |
| **Affected contract** | §7.3 rule 70, §9.3 comparability distinction |

## Finding

`ForcedItemRules::headline()` currently requires the candidate source ID to occur in `context.comparable_source_ids` and compares it only with other chart-group members. Chart-group participation is therefore a prerequisite for the forced rule. This is narrower than the intended document-level currency-group predicate. `ChartCandidateBuilder` eligibility and its `comparability` score signal are separate concerns and would remain unchanged.

## Proposed resolution

Rule 70 requires all of these conditions:

- `kind = metric`;
- `typed.value.unit_kind = currency`, non-null currency, and a valid scale-applied canonical numeric value;
- at least two valid metrics in the same document-level `unit_kind + currency` group;
- the largest **absolute** canonical magnitude in that group, with exact ties decided by §9.5;
- pre-forcing scored tier ≤ 2.

Remove the chart-group-membership requirement from the candidate and competitor checks. Keep the current score, tiers, tie order, chart eligibility, chart construction, and every other forced rule unchanged. A metric in Tier 3, including the existing “Total financing approved” fixture result, remains ineligible. Confidence and provider inference do not decide this rule.

## Why and alternatives

The current prerequisite lets chart availability decide whether an otherwise eligible headline measure can be forced. Keeping that prerequisite preserves current behavior but leaves the contract discrepancy. Expanding chart eligibility would alter an unrelated established surface and is not proposed.

## Verification

Test a largest currency metric with pre-forcing Tier ≤ 2 outside a chart group, a single-metric group, a Tier 3 largest metric, separate currencies, billion versus million scale, null currency, exact magnitude ties under §9.5, and confidence changes. Re-run fixture 25, fixture 26, chart candidate order, and the full backend suite.

**Approval notes:** The user approved this exact predicate. No scorer weights, bands, class bases, signal values, patterns, pre-forcing Tier ≤ 2 requirement, confidence policy, or `ChartCandidateBuilder` behavior may change.
