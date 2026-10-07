# CR-001: Represent records whose origin cannot be established

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | draft |
| **Approver** | Pending |
| **Approved on** | Pending |
| **Supersedes** | none |

## 1. Contract sections affected

| Section | What changes |
|---|---|
| §2.1 Origin | Add an unknown fallback for source-grounded records whose extracted statement is not demonstrably verbatim. |
| §2.4 Combination rules | Define the permitted assertion for unknown origin. |
| §2.5 Storage | Permit the fallback in `data.provenance`. |
| §3 Legacy mapping | Define how the fallback maps to V1 `basis`. |

Invariants affected: §2.4 and §3.2. No V1–V7, T1–T9, B1–B6, C1–C5, A1–A7, D1–D5, L1–L6 or F1–F5 invariant is weakened.

## 2. What is being changed

**Current:**

> `origin` — who produced this statement

> `document` | The document states it. DocIntel only located and typed it.

> `docintel_ai` | A model wrote it. Always carries `verification` (§14) and is always labelled as an AI summary in any surface that shows it (§13.6).

> `origin: document` ⇒ `assertion ∈ {stated}`.

The contract has no fallback when an accepted extraction row has grounded evidence but its `label` or `value` is paraphrased and available metadata does not establish whether the document directly asserted that wording. Calling it `document/stated` would overclaim; calling it an AI Brief block would require verification that extraction rows do not carry.

**Proposed:**

> `origin: unknown` means that accepted source evidence exists but stored evidence and extraction metadata do not establish whether the record's statement was directly asserted. Such a record has `assertion: inferred`, `attribution: unattributed`, and cannot support a `stated` Brief block or an absence claim. Its V1 `basis` maps to `inferred`. Analysts still receive the record and its original source evidence.

The exact API exposure and labelling of this fallback need approval before implementation.

## 3. Why

`document_evidence.data` retains model-produced `label` and `value` plus grounded `quote`/`sources`; the existing validator verifies evidence identifiers or quote presence, not semantic equivalence of each field. A deterministic substring check can establish some direct assertions, but a failed check does not establish that the document did not assert the claim elsewhere. The Stage A instruction expressly requires an unknown fallback rather than a guess.

## 4. Alternatives considered

| Alternative | Why not |
|---|---|
| Do nothing | Leaves accepted rows with an unrepresentable provenance state. |
| Mark every grounded extraction row `document/stated` | Confuses evidence fidelity with external truth and overstates directness. |
| Mark every uncertain row `docintel_ai/inferred` | Mislabels source assertions as AI prose and invokes Brief verification on records that are not Brief blocks. |

## 5. Provider-call impact

- [x] **No change.** No provider request, request count or request size changes.
- [ ] Request size changes.
- [ ] A new provider request is required.

## 6. Power BI impact

- [x] **No Power BI change.** No view, column, role, grant, RLS policy or Power BI test changes.

## 6b. Existing-implementation impact

- [x] Touches none of the existing `ImportantFindingsBuilder`, `TakeawayBuilder`, `ChartCandidateBuilder`, `MeasurementParser`, `PeriodParser` or `DocumentAnalysisComposer` decisions. The record remains available to those builders. Any future ranking treatment of `origin: unknown` needs its own approval.

## 7. Existing-API impact

- [x] Additive only. Existing API keys and the frontend `DocumentAnalysis` shape stay intact.

## 8. Database impact

- [x] No migration. `data.provenance` is an additive JSON key; `EvidenceMerger::identity()` is unchanged.

## 9. Re-extraction and versioning impact

- [x] `pipeline_key`, extraction version and span version are unaffected. No provider call or backfill.

## 9b. Branch prerequisite

- [x] This tree contains `App\Services\Intelligence\*`, `ff37df8`, `ProactiveChunkRisk.php` and the associated migration and tests. Backend branch: `feat/intelligence-v2-stage-a` from `b2dea3d`.

## 10. Legacy-document impact

| Population | Behaviour |
|---|---|
| Incremental, current key | Only rows lacking deterministic direct support use the fallback. |
| Normal route | Existing adapted rows remain bounded by §21.2; the fallback does not reconstruct missing evidence. |
| Superseded key | Rows remain invisible. |

- [x] No backfill, reprocessing or provider call.

## 11. Feature-flag behaviour

- [x] Flag-off output is unchanged. The fallback is only exposed by V2 when enabled.

## 12. Safety and correctness review

- [x] No model-supplied text becomes evidence, offset or number; unknown origin cannot assert absence.
- [x] Workspace scoping and metadata-only logging remain unchanged.

## 13. Conformance fixtures

- [x] No golden file changes in this draft. An approved implementation needs a new unknown-origin case; existing goldens must remain unchanged unless a separate contract approval names them.

## 14. Rollout and rollback

Rollout and rollback use the V2 flag. Additive JSON provenance, if cached, remains inert while the flag is off.

## 15. Open questions

Approve the fallback enum and its display label, or specify a different contract representation that does not overstate source directness.

## 16. Approval

- [ ] Contract §§2–3 updated in the same approved change.
- [ ] Existing implementation and API impact reviewed.
- [ ] Provider-call impact acknowledged: zero.

**Approver notes:** Pending.
