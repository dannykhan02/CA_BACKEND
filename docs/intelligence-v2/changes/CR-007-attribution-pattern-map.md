# CR-007: Specify deterministic attribution patterns and role mapping

| | |
|---|---|
| **Author** | Backend implementation |
| **Date** | 2026-10-07 |
| **Status** | approved |
| **Approver** | Stage A approver |
| **Approved on** | 2026-10-07 |
| **Supersedes** | none |

## 1. Contract sections affected

§2.3 Attribution, §7.3 `regulator_attributed`, §9.3 `attribution_authority`, §14 attribution verification, and the still-open Q5. No change to these rules is approved here.

## 2. Current contract and missing decision

The contract says attribution is derived where a span contains a reporting pattern DocIntel recognises and gives examples: `the auditor noted`, `the Authority alleges`, `management believes`. It does not specify an exact approved pattern set, word-boundary behavior, case behavior, a mapping from each phrase to an allowed attribution role, whether `reported` is true for each, or how a `speaker` entity ID is confirmed. Stage A Part 2 prohibits inventing patterns or enum values.

The committed provenance projector therefore uses the contract's safe default, `role: unattributed`, `speaker: null`, `reported: false`, for both document and unknown origin. It does not interpret model-reported origin fields. As a result, `regulator_attributed` cannot become active from an extracted quote through this projector until this decision is made.

## 3. Decision requested

**Approved decision:** English-only, config-driven and versioned attribution matching. The nearest approved lexical pattern to the claim wins. Exact tie or no match yields `role=unattributed`, `speaker=null`, `reported=false`. Management statements stay unattributed; do not infer `author`. All literal patterns live in config, never implementation code.

| Role | Approved patterns | `reported` |
|---|---|---|
| auditor | `the auditor noted`; `the auditors noted`; `the auditor found`; `the auditors found`; `the auditor reported`; `the auditors reported`; `the auditor concluded`; `the auditors concluded`; `audit found` | false |
| regulator | `the regulator alleges`; `the regulator found`; `the regulator requires`; `the regulator stated`; `the authority alleges`; `the authority found`; `the authority requires`; `the authority stated`; `the commission alleges`; `the commission found`; `the commission requires`; `the commission stated` | false |
| counterparty | `the counterparty states`; `the counterparty claims`; `the counterparty asserts`; `the supplier states`; `the supplier claims`; `the supplier asserts`; `the customer states`; `the customer claims`; `the customer asserts`; `the lender states`; `the lender claims`; `the lender asserts` | true |
| quoted | `according to <named speaker>`; `<named speaker> said`; `<named speaker> stated that` | true |
| third_party | `analysts suggest`; `analysts estimate`; `media suggest`; `media estimate`; `reports suggest`; `reports estimate` | true |

The cited span that contains the winning pattern establishes the attribution evidence reference. A speaker ID requires a confirmed, matching stored entity record; otherwise it is null.

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

- [x] Exact patterns and mappings supplied.
- [x] Contract §§2.3, 7.3, 9.3 and Q5 reconciled.
- [ ] New fixed-clock and source-grounded conformance tests specified.

**Approver notes:** Approved pattern map and nearest-match/default/reported rules above. Scorer constants and forced rules remain unchanged.
