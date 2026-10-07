# CR-005: Resolve scorer span-type vocabulary mismatch

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | approved |
| **Approver** | Stage A contract approver |
| **Approved on** | 2026-10-07 |
| **Supersedes** | none |

## 1. Contract sections affected

§9.2–9.3 (`structural_prominence` and `boilerplate_penalty` signal configuration/value rules) and §21.2 L1. Rule 80 and all other scorer values remain unchanged.

## 2. Current contract and observed implementation

> `heading_span_types = ['heading','table_header']`

> `boilerplate_span_types = ['header','footer']`

> If the actual stored span `type` vocabulary does not contain these names, STOP and write a change request. Do not silently map names.

`SourceSpanBuilder::classify()` emits `table_row`, `heading`, `list_item`, or intermediate `prose`; prose is segmented into `sentence`, and short heading content can become `section`. The exact persisted type vocabulary is `heading`, `section`, `table_row`, `list_item`, `sentence`. It never emits `table_header`, `header`, or `footer`. `EvidenceGrounding` persists builder output without a type remapping. The existing `SourceSpanBuilderTest` asserts these emitted names.

## 3. Decision requested

Approved: `heading_span_types = ['heading']` and `boilerplate_span_types = []` in materiality version `1`. For structural prominence, the heading branch is 1.0, the first-10%-ordinal branch is 0.5, and their maximum applies when both qualify. If no heading-like emitted type exists, skip that branch with `span_type_unavailable`; the ordinal branch remains available. No span data skips the entire structural signal. Boilerplate always contributes 0.0 with `skipped: true` and `reason: span_type_unavailable`, deferred to a later materiality version. No table-header or header/footer inference is authorized.

## 4. Why this blocks implementation

The original scorer type lists included names that never occur in the stored span vocabulary. The approved replacement resolves this without changing extraction or persisted spans. Fixture 25 remains an unchanged calibration gate.

## 5. Alternatives considered

| Alternative | Why not |
|---|---|
| Keep current config unchanged and silently yield zero for absent types | Violates the explicit stop rule and obscures a permanently unreachable penalty. |
| Treat `table_row` as `table_header` or `section` as `header` | Invents a semantic mapping unsupported by the stored vocabulary. |
| Change `SourceSpanBuilder` now | Extraction changes are outside this Stage A authorization. |

## 6. Impact

- Provider calls, request size, evidence budget, billing, and Power BI: no change from writing this CR.
- Existing API and database columns: no change from writing this CR.
- Existing scorer weights, bands, class bases, budgets, forced rules, date patterns, penalty patterns, normalization, tiebreaks, version and fixture gates: unchanged.
- Feature flag: no change; flag-off behaviour remains as committed.

## 7. Conformance and rollout

Implementation must test the configured type against real builder output, assert structural maximum and ordinal cases and boilerplate skipped/zero cases, run fixtures 25 and 26, and run the full backend suite. No rollout or migration is authorized here.

## 8. Approval

- [x] Contract §§9.2–9.3 and §21.2 L1 updated with the approved replacement.
- [ ] Fixture 25 ranking effects reviewed without retuning weights or bands.
- [x] No extraction, persisted-span or span-version change authorized or made.

**Approver notes:** Approved the real persisted vocabulary `heading`, `section`, `table_row`, `list_item`, `sentence`; only `heading` is unambiguously heading-like. Use `heading_span_types=['heading']`, first 10% ordinal value 0.5, and the maximum applicable prominence value. Set `boilerplate_span_types=[]`; skip its zero contribution with `span_type_unavailable` and defer the signal. Do not alter `SourceSpanBuilder`, `EvidenceGrounding`, span versions, or any other scorer parameter or calibration gate.
