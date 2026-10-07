# CR-004: Specify typed qualifiers and a stage coverage ledger

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
| §1.1 TypedValue | Decide how entity and actual/forecast/target qualifiers are represented; permit a documented trillion scale or specify unparsed handling. |
| §9.4 Versioning | State the initial `values.parser_version` string so typed artefacts can be stamped without inventing a value. |
| §10.2 CoverageState | Define stage-level ingestion, extraction and synthesis observability with unknown facts. |
| §10.3 Coverage rules | State how unknown counters affect coverage completeness. |

Invariants affected: V6, C1, C3 and C5. No change to due-date grounding or source evidence.

## 2. What is being changed

**Current:**

> `scale: 1 | 1e3 | 1e6 | 1e9 | null`

> `currency: string | null`

> `CoverageState { state: "complete" | "bounded" | "partial" | "unavailable" ... failed_chunks, total_chunks, dropped_records, saturated_chunks: int ... reasons: [string] }`

The Stage A task also names `entity` and actual/forecast/target within a typed value and asks for an ingestion/extraction/synthesis ledger with unknown facts. These fields have no contract shape. The existing `MeasurementParser` accepts trillion scale, which §1.1 does not represent.

**Proposed:**

> Add explicit nullable `entity_ref` and `measure_status: "actual" | "forecast" | "target" | null` to `TypedValue`, populated only from stored, grounded record metadata. Extend `scale` to `1e12` to match the existing parser. Add `CoverageState.stages` with named `ingestion`, `extraction` and `synthesis` entries, each carrying a status and `unknown_facts`; reserve `review` without emitting it. Unknown facts never permit `complete` or an absence claim.

The approved stage statuses, precedence, field shape and initial parser version are recorded in contract §§1, 9 and 10 and the approver notes below. The earlier open proposal is superseded.

## 3. Why

Without the typed qualifiers, consumers must re-read free-text subject and `metric_type` to distinguish a target from an actual or identify the measured entity. Without stage-level unknown facts, missing extraction counters can be serialized as zero, which resembles complete coverage. The current code can parse `1.2 trillion`; the contract's type cannot carry its scale.

## 4. Alternatives considered

| Alternative | Why not |
|---|---|
| Do nothing | Leaves the Stage A requested representation and ledger undefined. |
| Encode unknown counters as zero plus prose reasons | An integer zero remains machine-readable as an observed zero. |
| Convert trillion to billion in the parser | Rewrites the document's scale and changes existing parser behaviour. |

## 5. Provider-call impact

- [x] **No change.** These fields are computed from existing data and do not change request size or count.
- [ ] Request size changes.
- [ ] New provider request.

## 6. Power BI impact

- [x] **No Power BI change.** No feed, view, column, flag, test, role or grant changes.

## 6b. Existing-implementation impact

- [x] The existing `MeasurementParser` remains unchanged; its accepted trillion input is represented rather than re-parsed. The existing chart and takeaway decisions remain unchanged. Any integration into ranking still requires CR-003's approved parameters.

## 7. Existing-API impact

- [x] Additive V2-only fields. The committed frontend `DocumentAnalysis` interface stays unchanged; new record/coverage endpoint fields require an approved exact API shape.

## 8. Database impact

- [x] No migration is required if values are derived on read and optional metadata stays inside `document_evidence.data`. Evidence identity is unchanged.

## 9. Re-extraction and versioning impact

- [x] No `pipeline_key`, extraction-version or span-version change. `values.parser_version` would bump when typed parsing changes; no provider call.

## 9b. Branch prerequisite

- [x] Backend branch `feat/intelligence-v2-stage-a` starts at `b2dea3d`, which contains main, visualization work and `ff37df8`.

## 10. Legacy-document impact

| Population | Behaviour |
|---|---|
| Incremental | Qualifiers use stored evidence metadata; missing facts stay unknown. |
| Normal route | §21.2 remains `bounded`, with missing chunk facts explicitly unknown. |
| Superseded key | Never read. |

- [x] No backfill, reprocessing or provider calls.

## 11. Feature-flag behaviour

- [x] Flag off is unchanged; V2 fields are absent. Flag on computes from existing data.

## 12. Safety and correctness review

- [x] No source text, date, amount or entity is invented. Unknown coverage forbids absence. Workspace scoping and metadata-only logging remain unchanged.

## 13. Conformance fixtures

- [x] No golden file changed in this contract amendment. Implementation needs new trillion, target/actual and unknown-stage cases under §22; existing goldens require explicit approval to change.

## 14. Rollout and rollback

Rollout and rollback use the V2 flag. Any cached derived metadata is inert while off.

## 15. Open questions

Resolved: `entity_ref.id` requires a confirmed entity record and `entity_ref.text` carries the text; `values.parser_version` is `values.v1`. The exact stage shape is in §10.

## 16. Approval

- [x] Contract §§1, 9 and 10 updated in the same approved change.
- [ ] Conformance fixtures remain an implementation gate.
- [x] Provider-call impact acknowledged: zero.

**Approver notes:** Approved `TypedValue.scale` through `1e12`, nullable `measure_status` (`actual|forecast|target`) populated only from stored `metric_type`/`value_basis`, `entity_ref: {id: string|null, text: string}` with an id only for confirmed entity records, and `values.parser_version = "values.v1"`. Top-level coverage remains exactly `complete|bounded|partial|unavailable`; existing V1 counters retain integer types. Add `stages.ingestion`, `.extraction`, `.synthesis` with stage status `complete|bounded|partial|unavailable|unknown`, `unknown_facts`, and nullable unknown numeric diagnostics. Reserve but do not emit `review`. Apply the approved stage precedence and make unknown required facts prevent top-level complete with stable `<fact>_unknown` reasons. Use only currently observable builder facts and do not instrument extraction. Contract §§1, 9 and 10 were amended on approval.
