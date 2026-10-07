# CR-012: Specify V2 negative-claim prose screening patterns

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-08 |
| **Status** | draft; approval required |
| **Approver** | Pending |
| **Affected contract** | §12.3, Stage A Part 2 item F |

## Missing approved input

§12.3 requires a versioned `intelligence_v2.negative_claim.patterns` set before accepting AI prose on V2 surfaces. No pattern literals, pattern grammar, or version value were supplied in the approved Stage A decisions or current config. The existing `NegativeClaimGuard::absenceCheck()` covers declared deterministic absence predicates but does not screen prose. V2 takeaways and summary notes can contain synthesis prose, so those output paths need the approved screen before Stage A Part 2 can claim §12.3 conformance.

## Decision requested

Supply the exact English lexical patterns, matching rules and version string for negative claims on V2 takeaways, summary notes, and future Brief blocks. Specify whether a match rejects the whole candidate before takeaway quotas/deduplication, and whether the screen applies to pre-existing synthesis prose mirrored into V2 surfaces. The current §12.2 rule says a failed absence block is omitted, with no softened text.

No implementation pattern or substitute heuristic is proposed. Existing V1 summary arrays and flag-off behavior remain untouched. This item blocks only the prose-screening integration; deterministic `absenceCheck()` remains implemented and tested.
