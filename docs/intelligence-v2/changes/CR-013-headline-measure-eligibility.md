# CR-013: Align headline_measure with rule 70

| | |
|---|---|
| **Author** | Stage A hardening review |
| **Date** | 2026-10-08 |
| **Status** | proposed; approval required before code changes |
| **Affected contract** | §7.3 rule 70, §9.3 comparability distinction |

## Finding

`ForcedItemRules::headline()` currently requires the candidate source ID to occur in `context.comparable_source_ids` and compares it only with other chart-group members. Chart-group participation is therefore a prerequisite for the forced rule. This is narrower than the intended document-level currency-group predicate. `ChartCandidateBuilder` eligibility and its `comparability` score signal are separate concerns and would remain unchanged.

## Proposed resolution

Rule 70 would require all of these conditions:

- `kind = metric`;
- `typed.value.unit_kind = currency` and a numeric canonical magnitude;
- the largest-magnitude currency metric in the document's `unit_kind + currency` group;
- pre-forcing scored tier ≤ 2.

Remove the chart-group-membership requirement from the candidate and competitor checks. Keep the current score, tiers, tie order, chart eligibility, chart construction, and every other forced rule unchanged. A metric in Tier 3, including the existing “Total financing approved” fixture result, remains ineligible.

## Why and alternatives

The current prerequisite lets chart availability decide whether an otherwise eligible headline measure can be forced. Keeping that prerequisite preserves current behavior but leaves the contract discrepancy. Expanding chart eligibility would alter an unrelated established surface and is not proposed.

## Verification after approval

Test a largest currency metric with pre-forcing Tier ≤ 2 outside a chart group, a smaller same-currency metric, a non-currency metric, and a Tier 3 currency metric. Re-run fixture 25, fixture 26, chart candidate order, and the full backend suite. This request does not authorize implementation.
