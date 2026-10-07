# CR-012: Specify V2 negative-claim prose screening patterns

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-08 |
| **Status** | approved |
| **Approver** | User, 2026-10-08 |
| **Affected contract** | §12.3, Stage A Part 2 item F |

## Missing approved input

§12.3 requires a versioned `intelligence_v2.negative_claim.patterns` set before accepting AI prose on V2 surfaces. No pattern literals, pattern grammar, or version value were supplied in the approved Stage A decisions or current config. The existing `NegativeClaimGuard::absenceCheck()` covers declared deterministic absence predicates but does not screen prose. V2 takeaways and summary notes can contain synthesis prose, so those output paths need the approved screen before Stage A Part 2 can claim §12.3 conformance.

## Approved resolution

`negative_claim.version = "1"`. The exact English-only pattern groups are recorded in §12.3 and `config/intelligence_v2.php`. Matching is case-insensitive with word boundaries; any one match rejects the AI-authored V2 block with `negative_claim`. A match is only a safety trigger. A deterministic absence template is emitted only when a separately declared predicate passes the existing complete-coverage, zero-match and provenance guard. Without that declaration or when the guard fails, no absence block is emitted. A separately available non-absence deterministic template for the same cited records may still replace a rejected block under §14.4. No repair or provider call is made. The five legacy summary arrays remain unscreened.

**Accepted conservative false positives:** `lack of clarity` can describe uncertainty rather than document-wide absence; `not addressed in this section` may describe only a section; `complete coverage` may describe insurance coverage rather than evidence completeness. These are intentionally rejected on V2 AI surfaces under version 1. The screen does not attempt negation-scope analysis.

**Approver notes:** CR-012 approved as specified by the user. No scorer, tier, forced-rule, formatter, dedupe or chart value changes are authorized.
