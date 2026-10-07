# CR-005: Resolve scorer span-type vocabulary mismatch

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | draft; approval required |
| **Approver** | Pending |
| **Approved on** | Pending |
| **Supersedes** | none |

## 1. Contract sections affected

§9.2–9.3 (`structural_prominence` and `boilerplate_penalty` signal configuration/value rules); possibly §7.3 rule 80 if section semantics are clarified. No change is approved yet.

## 2. Current contract and observed implementation

> `heading_span_types = ['heading','table_header']`

> `boilerplate_span_types = ['header','footer']`

> If the actual stored span `type` vocabulary does not contain these names, STOP and write a change request. Do not silently map names.

`SourceSpanBuilder::classify()` emits `table_row`, `heading`, `list_item`, or `prose`; prose is segmented into `sentence`, and short heading content can become `section`. Persisted span types therefore include `table_row`, `heading`, `list_item`, `sentence`, and `section`. It never emits `table_header`, `header`, or `footer`. `EvidenceGrounding` persists builder output without a type remapping. The existing `SourceSpanBuilderTest` asserts these emitted names.

## 3. Decision requested

Approve an explicit replacement for the configured type lists and the exact signal behavior when a named type cannot exist. A contract-only change can use the current vocabulary, but must state whether `table_row` or `section` is ever prominent, whether boilerplate detection is disabled, and how skipped/zero contributions are represented. Alternatively, approve a separate extraction/span-classification change with its effects on span versions, pipeline keys, existing evidence, and provider cost. The present Stage A instruction forbids such an extraction change, so that alternative requires new authorization.

No default mapping or replacement list is proposed or implemented here.

## 4. Why this blocks implementation

The approved scorer assigns positive prominence and negative boilerplate weights using names that never occur in the stored span type vocabulary. Implementing the configured rules unchanged would silently make parts of both signals unreachable and could alter Tier 1 membership and fixture 25. Changing the type lists in code would invent a scorer parameter expressly prohibited by Stage A Part 2.

## 5. Alternatives considered

| Alternative | Why not |
|---|---|
| Keep current config unchanged and silently yield zero for absent types | Violates the explicit stop rule and obscures a permanently unreachable penalty. |
| Treat `table_row` as `table_header` or `section` as `header` | Invents a semantic mapping unsupported by the stored vocabulary. |
| Change `SourceSpanBuilder` now | Extraction changes are outside this Stage A authorization. |

## 6. Impact

- Provider calls, request size, evidence budget, billing, and Power BI: no change from writing this CR.
- Existing API and database columns: no change from writing this CR.
- Existing scorer code and fixtures: no change; scorer integration is stopped.
- Feature flag: no change; flag-off behaviour remains as committed.

## 7. Conformance and rollout

After approval, test each configured type against real builder output, assert signal positive and zero/skipped cases, rerun fixtures 25 and 26, and run the full backend suite. No rollout or migration is authorized by this draft.

## 8. Approval

- [ ] Contract §§9.2–9.3 updated with the approved replacement.
- [ ] Fixture 25 ranking effects reviewed without retuning weights or bands.
- [ ] Any extraction change separately authorized, if selected.

**Approver notes:** Pending.
