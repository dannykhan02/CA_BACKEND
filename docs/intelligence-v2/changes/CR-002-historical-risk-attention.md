# CR-002: Keep historical risk records informational

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | approved |
| **Approver** | Stage A contract approver |
| **Approved on** | 2026-10-07 |
| **Supersedes** | none |

## 1. Contract sections affected

| Section | What changes |
|---|---|
| §7.3 Forced-item rules | Clarify whether a historical source-classified critical risk is still forced into Tier 1. |
| §11.1 Attention states | Add an explicit historical-event exception to active attention. |

Invariants affected: T4 and §11.1. No storage, evidence or date-grounding invariant changes.

## 2. What is being changed

**Current:**

> `critical_risk` | `kind = risk` **and** `severity = critical`

> `needs_attention` | Tier 1 **and** forced by `critical_risk`, `overdue_dated_obligation`, `imminent_dated_obligation` or `penalised_obligation`.

The current text would label a past earthquake as needing attention solely because the source classified it as a critical risk, even if the evidence says it occurred in the past. The Stage A instruction requires historical events to be informational while preserving their risk record type.

**Proposed:**

> A risk record whose `observed_date` resolves to a past calendar date or an anchored period ending before `asOf`, and for which no other same-span record has a future date role or open status, remains a risk record, retains its scored tier, is not forced by the critical/high risk rules, and has `informational` attention with reason `historical_context`. A missing or unresolved observed date does not trigger this exception.

The approved exception does not consume a forced Tier 1 slot. A continuing consequence grounded only in a different span may not suppress it in V1.

## 3. Why

The contract treats severity as a forced attention rule but does not distinguish an active threat from a historical event. Reclassifying the record as a fact would overwrite the source-derived kind, violating the stated source-fidelity principle.

## 4. Alternatives considered

| Alternative | Why not |
|---|---|
| Do nothing | A past earthquake can appear as a current action item. |
| Rewrite historical risks as facts | Destroys the source's classification. |
| Ignore severity for all old records | A dated risk may still have an active consequence; too broad. |

## 5. Provider-call impact

- [x] **No change.** The decision uses existing grounded date roles and no new call or request bytes.
- [ ] Request size changes.
- [ ] New provider request.

## 6. Power BI impact

- [x] **No Power BI change.** No Power BI code, schema, grants or tests change.

## 6b. Existing-implementation impact

- [x] Touches `ImportantFindingsBuilder` only if approval changes Tier 1 placement; its current seven-class order must otherwise be preserved. `TakeawayBuilder` and `ChartCandidateBuilder` decisions remain unchanged. A separate conformance case is required for any approved ranking change.

## 7. Existing-API impact

- [x] Additive V2 attention data only. Existing risk kind, severity, status and frontend `DocumentAnalysis` fields remain unchanged.

## 8. Database impact

- [x] No migration or constraint change. Source-derived record data is preserved.

## 9. Re-extraction and versioning impact

- [x] No `pipeline_key` or extraction-version change, no re-extraction, no provider calls. An approved attention rule change bumps `materiality.version` as §9.4 requires.

## 9b. Branch prerequisite

- [x] Backend tree contains visualization work and `ff37df8`; branch `feat/intelligence-v2-stage-a` started at `b2dea3d`.

## 10. Legacy-document impact

| Population | Behaviour |
|---|---|
| Incremental | Grounded `observed_date` can establish historical context. |
| Normal route | No invented observed date; unresolved timing remains unknown. |
| Superseded key | Not read. |

- [x] No backfill or reprocessing.

## 11. Feature-flag behaviour

- [x] Flag off retains the pre-V2 output; the proposed attention state exists only with V2 enabled.

## 12. Safety and correctness review

- [x] The exception requires grounded date evidence and cannot make an absence claim.
- [x] It never changes the stored kind, severity or due date.

## 13. Conformance fixtures

- [x] No golden changes in this contract amendment. Implementation requires a new fixed-clock past-earthquake case and an explicit review of any changed Tier 1 golden.

## 14. Rollout and rollback

Rollout and rollback use the V2 flag. No legacy data is rewritten.

## 15. Open questions

Resolved: it remains in its scored tier and is exempt from critical/high risk forced rules.

## 16. Approval

- [x] Contract §§7 and 11 updated in the same approved change.
- [x] T9 ranking impact is subject to fixture 25.
- [x] Provider-call impact acknowledged: zero.

**Approver notes:** Approved conservative V1 rule. A risk with an `observed_date` resolving to a past calendar date or anchored period ending before injectable `asOf`, with no other same-span record carrying a future date role or open status, retains its risk kind and scored tier, is exempt from critical/high-risk forced Tier 1 rules, and has `informational` attention with reason `historical_context`. Missing or unresolved observed dates do not qualify. A consequence grounded only in a different span may not suppress this exception; no semantic or cross-span inference is authorized. Contract §§7.3 and 11.1 were amended on approval.
