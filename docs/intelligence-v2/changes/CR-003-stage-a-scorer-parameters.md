# CR-003: Specify the Stage A scorer and role-pattern parameters

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | draft; product values required |
| **Approver** | Pending |
| **Approved on** | Pending |
| **Supersedes** | none |

## 1. Contract sections affected

| Section | What changes |
|---|---|
| §4.4 Role inference | Supply the fixed, versioned date-role pattern set. |
| §9.2 Thresholds | Supply each signal's numeric weight. |
| §9.3 Signals | Supply signal-specific parameters and an exact score normalization rule. |
| §7.3 Forced-item rules | Specify the penalty/consequence pattern and Tier ≤ 2 neighbourhood rule. |

Invariants affected: T1–T9 and the scorer's reason-sum invariant. No proposal here changes provider calls or evidence retention.

## 2. What is being changed

**Current:**

> `'weights'    => [ <signal id> => float, ... ]`

> `'signals'    => [ <signal id> => [...signal-specific parameters] ]`

> Roles are assigned deterministically from the cited span's surrounding text using a fixed, versioned pattern set owned by the same config as the scorer (§9).

> `penalised_obligation` | `kind = obligation` **and** a typed `extras` money or percent value whose span carries a penalty/consequence pattern

The contract gives the tier bands, budgets, and `imminent_days` proposed value, but no weights, score normalization rule, date-role patterns, or penalty patterns. The current seven-class usefulness table cannot uniquely determine fourteen signal weights or their interactions.

**Proposed:**

> `config/intelligence_v2.php` contains an approved numeric weight for each §9.3 signal, any signal-specific parameters, a normalization rule that yields a score comparable with §9.2's 0.72/0.45/0.18 bands, and the exact versioned date-role and penalty/consequence pattern lists. No parameter is inferred from code defaults.

The concrete values and patterns must be supplied by the contract approver before this CR can be approved or implemented.

## 3. Why

Stage A requires one deterministic scorer and machine-readable contributions. Multiple plausible weight sets can reproduce the existing order on a small corpus yet disagree on real documents, Tier 1 membership, and synthesis selection. Choosing one would invent product thresholds contrary to the task instruction.

## 4. Alternatives considered

| Alternative | Why not |
|---|---|
| Do nothing | Stage A scorer and role inference remain blocked. |
| Copy the seven-class ranking and set other weights to zero | Violates the specified initial signal set and does not define the 0–1 score bands. |
| Pick plausible weights and patterns in code | Unapproved product behaviour; violates §9.1. |

## 5. Provider-call impact

- [x] **No change.** Pure deterministic scoring and pattern matching; zero new requests and no size change.
- [ ] Request size change.
- [ ] New provider request.

## 6. Power BI impact

- [x] **No Power BI change.** No view, column, flag, role, grant, RLS policy or Power BI test changes.

## 6b. Existing-implementation impact

- [x] Touches `ImportantFindingsBuilder` tier selection, `TakeawayBuilder` quotas and `EvidenceBudget` ordering when V2 is enabled; `ChartCandidateBuilder` score may only be moved without changing candidate order under §16.3a. T9's existing corpus is a required regression gate. Exact ranking effects cannot be stated until values are supplied.

## 7. Existing-API impact

- [x] Additive V2 fields only. Flag-off JSON remains unchanged and `DocumentAnalysis` keeps its declared shape.

## 8. Database impact

- [x] No migration. Any cached tier artefact uses existing additive JSON or an approved later design; evidence identity is unchanged.

## 9. Re-extraction and versioning impact

- [x] No `pipeline_key`, extraction or span-version change. Approved parameter changes bump `materiality.version`; no provider calls.

## 9b. Branch prerequisite

- [x] Backend branch `feat/intelligence-v2-stage-a` starts from `b2dea3d`, which contains main, visualization work and `ff37df8`.

## 10. Legacy-document impact

| Population | Behaviour |
|---|---|
| Incremental | Full available signals and explicit skipped reasons when data is missing. |
| Normal route | Signals lacking source offsets contribute zero and are marked skipped under L1. |
| Superseded key | Never read. |

- [x] No backfill, reprocessing or provider call.

## 11. Feature-flag behaviour

- [x] Flag off keeps the pre-V2 ranking and request order. Flag on uses the approved config only.

## 12. Safety and correctness review

- [x] No confidence-as-materiality signal and no record deletion.
- [x] The 16,000-byte evidence budget remains a hard bound; any changed order only affects which records fit.
- [x] Workspace scoping and metadata-only logs remain unchanged.

## 13. Conformance fixtures

- [x] No golden files changed in this draft. Approval must specify the exact `config.json` values for scorer, date-role, forced-rule and deterministic-tiebreak cases, including fixture 25 and 26.

## 14. Rollout and rollback

The V2 flag controls rollout and rollback. Parameter version bumps recompute deterministic artefacts on read and make no provider call.

## 15. Open questions

Provide the full weight table, score normalization, signal parameters, date-role patterns, penalty patterns and the Tier ≤ 2 neighbourhood definition. The `imminent_days` proposed value of 90 is already defined by §7.3 and is not requested again.

## 16. Approval

- [ ] Concrete values and patterns supplied and contract §§4, 7 and 9 updated in the same change.
- [ ] T9 ranking effects and any approved difference reviewed.
- [ ] Provider-call impact acknowledged: zero.

**Approver notes:** Pending.
