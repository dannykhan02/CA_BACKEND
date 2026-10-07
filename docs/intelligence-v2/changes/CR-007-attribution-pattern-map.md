# CR-007: Specify deterministic attribution patterns and role mapping

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | draft; approval required |
| **Approver** | Pending |
| **Approved on** | Pending |
| **Supersedes** | none |

## 1. Contract sections affected

§2.3 Attribution, §7.3 `regulator_attributed`, §9.3 `attribution_authority`, §14 attribution verification, and the still-open Q5. No change to these rules is approved here.

## 2. Current contract and missing decision

The contract says attribution is derived where a span contains a reporting pattern DocIntel recognises and gives examples: `the auditor noted`, `the Authority alleges`, `management believes`. It does not specify an exact approved pattern set, word-boundary behavior, case behavior, a mapping from each phrase to an allowed attribution role, whether `reported` is true for each, or how a `speaker` entity ID is confirmed. Stage A Part 2 prohibits inventing patterns or enum values.

The committed provenance projector therefore uses the contract's safe default, `role: unattributed`, `speaker: null`, `reported: false`, for both document and unknown origin. It does not interpret model-reported origin fields. As a result, `regulator_attributed` cannot become active from an extracted quote through this projector until this decision is made.

## 3. Decision requested

Approve an exact versioned list of quoted reporting patterns with each pattern's role, `reported` value, speaker resolution rule and evidence reference rule; or explicitly approve an unattributed-only Stage A V1 and defer the forced regulator rule with corresponding amendments to §§2.3, 7.3 and 9.3. The latter changes an approved forced rule and needs explicit sign-off. No phrase-to-role mapping is proposed or implemented by this CR.

## 4. Alternatives considered

| Alternative | Why not |
|---|---|
| Infer mappings from the three examples | `the Authority` and `management` do not by themselves prove a specific allowed role or entity record; new patterns would be invented. |
| Trust model-reported attribution | Violates §2.4 and the Stage A instruction to derive provenance from stored evidence. |
| Default all records to author | Directly violates §2.3's `unattributed` default. |

## 5. Impact

- No provider call, request size, billing, extraction, OCR, chunk, span-set, migration, frontend or Power BI change is requested by this draft.
- Flag-off output remains unchanged.
- Fixture 25 and 26 stay unchanged and must be rerun after any approved attribution rule is implemented, without retuning scorer parameters.
- Unknown-origin records always remain `unspecified` and unattributed under CR-001, regardless of any future pattern approval.

## 6. Approval

- [ ] Exact patterns and mappings supplied, or an explicit deferral of attribution-based forcing approved.
- [ ] Contract §§2.3, 7.3, 9.3 and Q5 reconciled.
- [ ] New fixed-clock and source-grounded conformance tests specified.

**Approver notes:** Pending.
